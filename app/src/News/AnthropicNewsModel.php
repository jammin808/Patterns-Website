<?php

declare(strict_types=1);

namespace Psc\News;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaRawMessageStartEvent;
use Anthropic\Client;
use Anthropic\Lib\Streaming\MessageAccumulator;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use Psc\App;

/**
 * The desk's model calls through the official Anthropic PHP SDK (docs/NEWS.md §4, AnthropicNewsModel).
 *
 * - Every call streams on the beta path through MessageAccumulator::forBetaMessages(), with adaptive thinking, the
 *   effort the pipeline asks for and the top-level cache marker, so the system prompt and each continuation's
 *   history are read from the cache. Opus and Sonnet calls opt into server-side refusal fallbacks ("default");
 *   Haiku has none. A stream that ends before message_stop, or without a stop reason, is a failure.
 * - The HTTP client is a Guzzle client of its own on curl: 600 seconds for a whole call, 15 to connect, and a call
 *   that receives nothing for two minutes is dropped. Research is never retried (a retry would run and bill its
 *   searches again); the other calls are retried once.
 * - Research resumes a paused turn (pause_turn) up to MAX_CONTINUATIONS times: the content goes back verbatim with
 *   the container, and each tool's max_uses is what is left of the session's allowance. The API's least max_uses is
 *   1, so a spent tool stays declared (the history names it) with 1, and once both are spent the model is told to
 *   write the brief now.
 * - The references are every address the research saw: search results, the pages read (the address asked for and
 *   the one fetched) and the search results the text cites, numbered S1, S2 ... in the order first seen.
 * - Usage is reported after every response through the call's after hook, by the model that served it (a fallback's
 *   share by its own model), web searches and page reads included.
 */
final class AnthropicNewsModel implements NewsModel
{
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /** How many times research resumes a paused turn. */
    public const MAX_CONTINUATIONS = 4;

    /** Curl drops a call that receives less than LOW_SPEED_LIMIT bytes a second for LOW_SPEED_TIME seconds. */
    public const LOW_SPEED_LIMIT = 1;
    public const LOW_SPEED_TIME = 120;

    private Client $client;

    /** @param HandlerStack|null $handler the HTTP handler (tests pass a mock); null: curl */
    public function __construct(string $apiKey, float $timeoutSeconds = 600.0, float $connectTimeoutSeconds = 15.0, ?HandlerStack $handler = null)
    {
        $http = new \GuzzleHttp\Client([
            'handler' => $handler ?? HandlerStack::create(new CurlHandler()),
            'timeout' => $timeoutSeconds,
            'connect_timeout' => $connectTimeoutSeconds,
            'curl' => [CURLOPT_LOW_SPEED_LIMIT => self::LOW_SPEED_LIMIT, CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_TIME],
        ]);
        $this->client = new Client(apiKey: $apiKey, requestOptions: ['transporter' => $http, 'maxRetries' => 1], openTelemetry: false);
    }

    /** Whether the SDK, Guzzle and curl are installed. */
    public static function available(): bool
    {
        return class_exists(Client::class) && class_exists(\GuzzleHttp\Client::class) && class_exists(CurlHandler::class) && extension_loaded('curl');
    }

    /** The desk's model with the configured key and timeouts, or null without a key or the SDK. */
    public static function fromConfig(App $app): ?self
    {
        $key = Settings::apiKey($app);
        if ($key === '' || !self::available()) {
            return null;
        }
        return new self($key, (float) $app->config->int('news.timeout_seconds', 600), (float) $app->config->int('news.connect_timeout_seconds', 15));
    }

    public function research(array $call): array
    {
        $messages = $call['messages'];
        $tools = is_array($call['tools'] ?? null) ? $call['tools'] : [];
        $left = ['web_search' => self::uses($tools, 'web_search'), 'web_fetch' => self::uses($tools, 'web_fetch')];
        $refs = [];
        $text = [];
        $usage = self::zero();
        $container = null;
        $final = false;
        for ($round = 0; ; $round++) {
            $msg = $this->send($call, $messages, $round === 0 ? $tools : self::withUses($tools, $left), $container, 0);
            $this->collect($msg, $refs, $text);
            $used = self::usage($msg, (string) $call['model']);
            foreach ($used as $part) {
                $usage = self::add($usage, $part);
            }
            $u = $msg->usage->serverToolUse;
            $left['web_search'] -= (int) ($u?->webSearchRequests ?? 0);
            $left['web_fetch'] -= (int) ($u?->webFetchRequests ?? 0);
            $container = $msg->container?->id ?? $container;
            if ($msg->stopReason !== 'pause_turn' || $round >= self::MAX_CONTINUATIONS) {
                break;
            }
            $messages[] = ['role' => 'assistant', 'content' => $msg->content];
            if (!$final && $left['web_search'] <= 0 && $left['web_fetch'] <= 0) {
                $messages[] = ['role' => 'user', 'content' => Prompts::WRITE_NOW];
                $final = true;
            }
        }
        $numbered = [];
        foreach (array_values($refs) as $i => $ref) {
            $numbered['S' . ($i + 1)] = $ref;
        }
        return [
            'text' => self::brief(implode("\n", $text)),
            'refs' => $numbered,
            'usage' => $usage,
            'stop' => (string) $msg->stopReason,
            'model' => $msg->model,
            'container' => $container,
        ];
    }

    public function structured(array $call): array
    {
        $msg = $this->send($call, $call['messages'], null, null, 1);
        $usage = self::zero();
        foreach (self::usage($msg, (string) $call['model']) as $part) {
            $usage = self::add($usage, $part);
        }
        $data = null;
        if (in_array($msg->stopReason, ['end_turn', 'stop_sequence'], true)) {
            $json = '';
            foreach ($msg->content as $block) {
                if ($block->type === 'text') {
                    $json .= $block->text;
                }
            }
            $decoded = json_decode(trim($json), true);
            $data = is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
        }
        return ['data' => $data, 'usage' => $usage, 'stop' => (string) $msg->stopReason, 'model' => $msg->model];
    }

    /**
     * The text between the brief's marker lines, every such part joined; a brief whose end line is missing runs to
     * the end of the text. Empty when no start line was written.
     */
    public static function brief(string $text): string
    {
        $parts = [];
        $offset = 0;
        while (($start = strpos($text, Prompts::BRIEF_START, $offset)) !== false) {
            $from = $start + strlen(Prompts::BRIEF_START);
            $end = strpos($text, Prompts::BRIEF_END, $from);
            $parts[] = trim($end === false ? substr($text, $from) : substr($text, $from, $end - $from));
            if ($end === false) {
                break;
            }
            $offset = $end + strlen(Prompts::BRIEF_END);
        }
        return trim(implode("\n\n", array_filter($parts, static fn (string $p): bool => $p !== '')));
    }

    /**
     * One streamed request, its usage reported through the after hook as soon as the API has told it, even when the
     * stream then breaks.
     *
     * @param array<string, mixed> $call
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>>|null $tools
     */
    private function send(array $call, array $messages, ?array $tools, ?string $container, int $maxRetries): BetaMessage
    {
        if (is_callable($call['before'] ?? null)) {
            ($call['before'])();
        }
        $model = (string) $call['model'];
        $outputConfig = ['effort' => (string) ($call['effort'] ?? 'high')];
        if (is_array($call['schema'] ?? null)) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $call['schema']];
        }
        $params = [
            'maxTokens' => (int) $call['maxTokens'],
            'messages' => $messages,
            'model' => $model,
            'cacheControl' => ['type' => 'ephemeral'],
            'system' => (string) $call['system'],
            'thinking' => ['type' => 'adaptive'],
            'outputConfig' => $outputConfig,
            'requestOptions' => ['maxRetries' => $maxRetries],
        ];
        if ($tools !== null && $tools !== []) {
            $params['tools'] = $tools;
        }
        if ($container !== null) {
            $params['container'] = $container;
        }
        if (!str_starts_with($model, 'claude-haiku')) {
            $params['fallbacks'] = 'default';
            $params['betas'] = [self::FALLBACK_BETA];
        }
        $stream = $this->client->beta->messages->createStream(...$params);
        $accumulator = MessageAccumulator::forBetaMessages();
        $started = false;
        $broken = null;
        try {
            foreach ($stream as $event) {
                $accumulator->accumulate($event);
                $started = $started || $event instanceof BetaRawMessageStartEvent;
            }
        } catch (\Throwable $e) {
            $broken = $e;
        }
        $msg = $started ? $accumulator->message() : null;
        if ($msg instanceof BetaMessage && is_callable($call['after'] ?? null)) {
            foreach (self::usage($msg, $model) as $served => $part) {
                ($call['after'])($part, (string) $served);
            }
        }
        if ($broken !== null) {
            throw $broken;
        }
        if (!$msg instanceof BetaMessage || !$accumulator->isComplete() || $msg->stopReason === null) {
            throw new \RuntimeException('The answer from ' . $model . ' stopped before it was complete.');
        }
        return $msg;
    }

    /**
     * Adds a response's references and text. A reference is kept once, by its canonical address: a page read makes
     * it via fetch, a citation makes it cited.
     *
     * @param array<string, array{url: string, title: string, via: string, page_age: ?string, cited: bool}> $refs
     * @param list<string> $text
     */
    private function collect(BetaMessage $msg, array &$refs, array &$text): void
    {
        $asked = [];
        $add = static function (string $url, ?string $title, string $via, ?string $pageAge = null, bool $cited = false) use (&$refs): void {
            $url = trim($url);
            $key = mb_strlen($url) <= 2000 ? Urls::key($url) : null;
            if ($key === null) {
                return;
            }
            $title = Prose::cut(trim((string) $title), 300);
            if (!isset($refs[$key])) {
                $refs[$key] = ['url' => $url, 'title' => $title, 'via' => $via, 'page_age' => $pageAge, 'cited' => $cited];
                return;
            }
            $r = &$refs[$key];
            $r['via'] = $r['via'] === 'fetch' || $via === 'fetch' ? 'fetch' : $r['via'];
            $r['title'] = $r['title'] !== '' ? $r['title'] : $title;
            $r['page_age'] ??= $pageAge;
            $r['cited'] = $r['cited'] || $cited;
        };
        $words = '';
        foreach ($msg->content as $block) {
            switch ($block->type) {
                case 'server_tool_use':
                    if ($block->name === 'web_fetch' && is_string($block->input['url'] ?? null)) {
                        $asked[$block->id] = $block->input['url'];
                    }
                    break;
                case 'web_search_tool_result':
                    if (is_array($block->content)) {
                        foreach ($block->content as $result) {
                            $add((string) $result->url, $result->title, 'search', $result->pageAge);
                        }
                    }
                    break;
                case 'web_fetch_tool_result':
                    $page = $block->content;
                    if ($page->type === 'web_fetch_result') {
                        $title = $page->content->title ?? null;
                        if (isset($asked[$block->toolUseID])) {
                            $add($asked[$block->toolUseID], $title, 'fetch');
                        }
                        $add((string) $page->url, $title, 'fetch');
                    }
                    break;
                case 'text':
                    $words .= $block->text;
                    foreach ($block->citations ?? [] as $citation) {
                        if ($citation->type === 'web_search_result_location') {
                            $add((string) $citation->url, $citation->title, 'search', null, true);
                        }
                    }
                    break;
            }
        }
        $text[] = $words;
    }

    /**
     * A response's usage by the model that served it. After a refusal fallback the per-attempt figures (iterations)
     * are the truth: each attempt is priced by its own model. Searches and page reads go to the model that answered.
     *
     * @return array<string, array<string, int>> model => usage
     */
    private static function usage(BetaMessage $msg, string $requested): array
    {
        $u = $msg->usage;
        $out = [];
        $parts = [];
        foreach ($u->iterations ?? [] as $it) {
            if (in_array($it->type ?? '', ['message', 'fallback_message'], true)) {
                $parts[] = [(string) ($it->model ?? $requested), (int) $it->inputTokens, (int) $it->outputTokens, (int) $it->cacheCreationInputTokens, (int) $it->cacheReadInputTokens];
            }
        }
        if ($parts === []) {
            $parts[] = [$msg->model !== '' ? $msg->model : $requested, (int) $u->inputTokens, (int) $u->outputTokens, (int) ($u->cacheCreationInputTokens ?? 0), (int) ($u->cacheReadInputTokens ?? 0)];
        }
        foreach ($parts as [$model, $in, $outTokens, $write, $read]) {
            $out[$model] = self::add($out[$model] ?? self::zero(), ['usage_in' => $in, 'usage_out' => $outTokens, 'cache_write' => $write, 'cache_read' => $read]);
        }
        $served = $msg->model !== '' ? $msg->model : $requested;
        $out[$served] = self::add($out[$served] ?? self::zero(), [
            'searches' => (int) ($u->serverToolUse?->webSearchRequests ?? 0),
            'fetches' => (int) ($u->serverToolUse?->webFetchRequests ?? 0),
        ]);
        return $out;
    }

    /** @param list<array<string, mixed>> $tools */
    private static function uses(array $tools, string $name): int
    {
        foreach ($tools as $tool) {
            if (($tool['name'] ?? '') === $name) {
                return max(0, (int) ($tool['maxUses'] ?? $tool['max_uses'] ?? 0));
            }
        }
        return 0;
    }

    /**
     * The tools with max_uses set to what is left of each allowance (never under 1, the API's least).
     *
     * @param list<array<string, mixed>> $tools
     * @param array<string, int> $left
     * @return list<array<string, mixed>>
     */
    private static function withUses(array $tools, array $left): array
    {
        foreach ($tools as $i => $tool) {
            $name = (string) ($tool['name'] ?? '');
            if (isset($left[$name])) {
                unset($tools[$i]['max_uses']);
                $tools[$i]['maxUses'] = max(1, $left[$name]);
            }
        }
        return $tools;
    }

    /** @return array<string, int> */
    private static function zero(): array
    {
        return ['usage_in' => 0, 'usage_out' => 0, 'cache_write' => 0, 'cache_read' => 0, 'searches' => 0, 'fetches' => 0];
    }

    /**
     * @param array<string, int> $a
     * @param array<string, int> $b
     * @return array<string, int>
     */
    private static function add(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $a[$k] = ($a[$k] ?? 0) + (int) $v;
        }
        return $a;
    }
}
