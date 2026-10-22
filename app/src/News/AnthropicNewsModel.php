<?php

declare(strict_types=1);

namespace Psc\News;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaRawMessageStartEvent;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\ErrorType;
use Anthropic\Lib\Streaming\MessageAccumulator;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\FnStream;
use Psc\App;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The desk's model calls through the official Anthropic PHP SDK (docs/NEWS.md §4, AnthropicNewsModel).
 *
 * - Every call streams on the beta path through MessageAccumulator::forBetaMessages(), with adaptive thinking and the
 *   effort the pipeline asks for. Research and its continuations carry the top-level cache marker, so each
 *   continuation reads the history before it from the cache; any other call caches only what its own messages mark
 *   (compose marks the part every compose call of a session shares). Opus and Sonnet calls opt into server-side
 *   refusal fallbacks ("default"); Haiku has none. A stream that ends before message_stop, or without a stop reason,
 *   is a failure.
 * - Streams go through PHP's stream handler, so events arrive as they are sent (curl hands a body over only once the
 *   whole of it has arrived): a wait of LOW_SPEED_TIME seconds for the connection, the headers or the next bytes
 *   ends a call, and so does reading past the call's timeout (600 seconds) from its start. Without allow_url_fopen
 *   there is no stream handler and streams go through curl, all at once.
 * - The SDK never retries. A call is sent once more, after its before hook has run again, only when the API answered
 *   429 or 529, or sent an overloaded error, before any event: nothing was generated or billed then. Any other
 *   failure is thrown, so a call is never billed twice unseen.
 * - Research resumes a paused turn (pause_turn) as it is, up to MAX_CONTINUATIONS times: the content goes back
 *   verbatim with the container, and each tool's max_uses is what is left of the session's allowance. The API's least
 *   max_uses is 1, so a spent tool stays declared (the history names it) with 1. Nothing is ever added after paused
 *   content (it can end in a tool call the API has yet to run): once both allowances are spent, or the continuations
 *   are, a last call without tools writes the brief from the research text so far and the references.
 * - The references are every address the research saw: search results, the pages read (the address asked for and
 *   the one fetched) and the search results the text cites, numbered S1, S2 ... in the order first seen.
 * - Usage is reported after every response through the call's after hook, by the model that served it (a fallback's
 *   share by its own model), web searches and page reads included. A stream that breaks reports what its events had
 *   told by then, with the results that had arrived and its text counted at least.
 */
final class AnthropicNewsModel implements NewsModel
{
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /** How many times research resumes a paused turn. */
    public const MAX_CONTINUATIONS = 4;

    /** A call that receives nothing for LOW_SPEED_TIME seconds is dropped (curl: under LOW_SPEED_LIMIT bytes a second). */
    public const LOW_SPEED_LIMIT = 1;
    public const LOW_SPEED_TIME = 120;

    /** The wait before the one resend of an overloaded call, in seconds, when the API names none; at most RETRY_WAIT_MAX. */
    public const RETRY_WAIT = 2.0;
    public const RETRY_WAIT_MAX = 30.0;

    private Client $client;

    /** @param HandlerStack|null $handler the HTTP handler for every call (tests pass a mock); null: PHP's stream handler for streams, curl otherwise */
    public function __construct(string $apiKey, float $timeoutSeconds = 600.0, float $connectTimeoutSeconds = 15.0, ?HandlerStack $handler = null)
    {
        $http = new \GuzzleHttp\Client([
            'handler' => $handler ?? HandlerStack::create(new CurlHandler()),
            'timeout' => $timeoutSeconds,
            'connect_timeout' => $connectTimeoutSeconds,
            'curl' => [CURLOPT_LOW_SPEED_LIMIT => self::LOW_SPEED_LIMIT, CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_TIME],
        ]);
        $options = ['transporter' => $http, 'maxRetries' => 0];
        if ($handler !== null || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $options['streamingTransporter'] = self::streaming($timeoutSeconds, $handler);
        }
        $this->client = new Client(apiKey: $apiKey, requestOptions: $options, openTelemetry: false);
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

    /**
     * The client for streamed calls, on PHP's stream handler: each wait on the socket (the connection, the headers, the
     * next bytes) is at most LOW_SPEED_TIME seconds, and a read past $timeoutSeconds from the start of the request
     * fails, by a monotonic clock. A read that comes back empty because it timed out fails too, so a stalled stream is
     * never waited on for ever.
     *
     * @param HandlerStack|null $handler the HTTP handler (tests pass a mock); null: PHP's stream handler
     */
    public static function streaming(float $timeoutSeconds, ?HandlerStack $handler = null): ClientInterface
    {
        $http = new \GuzzleHttp\Client([
            'handler' => $handler ?? HandlerStack::create(new StreamHandler()),
            'timeout' => self::LOW_SPEED_TIME,
            'read_timeout' => self::LOW_SPEED_TIME,
        ]);
        return new class ($http, $timeoutSeconds) implements ClientInterface {
            public function __construct(private readonly \GuzzleHttp\Client $http, private readonly float $timeoutSeconds)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $deadline = hrtime(true) + (int) ($this->timeoutSeconds * 1e9);
                // The SDK's own options for a stream: error statuses and redirects come back as responses.
                $response = $this->http->send($request, ['stream' => true, 'http_errors' => false, 'allow_redirects' => false]);
                $body = $response->getBody();
                $limit = $this->timeoutSeconds;
                return $response->withBody(FnStream::decorate($body, [
                    'read' => static function (int $length) use ($body, $deadline, $limit): string {
                        if (hrtime(true) >= $deadline) {
                            throw new \RuntimeException('The answer took longer than ' . $limit . ' seconds.');
                        }
                        $chunk = $body->read($length);
                        if ($chunk === '' && $body->getMetadata('timed_out') === true) {
                            throw new \RuntimeException('Nothing arrived for ' . AnthropicNewsModel::LOW_SPEED_TIME . ' seconds.');
                        }
                        return $chunk;
                    },
                ]));
            }
        };
    }

    public function research(array $call): array
    {
        $messages = $call['messages'];
        $tools = is_array($call['tools'] ?? null) ? $call['tools'] : [];
        $left = ['web_search' => self::uses($tools, 'web_search'), 'web_fetch' => self::uses($tools, 'web_fetch')];
        $refs = [];
        $text = [];
        $written = null;
        $usage = self::zero();
        $container = null;
        for ($round = 0; ; $round++) {
            $msg = $this->send($call, $messages, $round === 0 ? $tools : self::withUses($tools, $left), $container, true);
            $this->collect($msg, $refs, $text);
            $usage = self::total($usage, $msg, (string) $call['model']);
            $u = $msg->usage->serverToolUse;
            $left['web_search'] -= (int) ($u?->webSearchRequests ?? 0);
            $left['web_fetch'] -= (int) ($u?->webFetchRequests ?? 0);
            $container = $msg->container?->id ?? $container;
            if ($msg->stopReason !== 'pause_turn') {
                break;
            }
            if (($left['web_search'] <= 0 && $left['web_fetch'] <= 0) || $round >= self::MAX_CONTINUATIONS) {
                // The paused turn is left as it is; a fresh call without tools writes the brief from what was found.
                $msg = $this->send($call, [['role' => 'user', 'content' => self::writeUp($call['messages'], $text, $refs)]], null, null, false);
                $written = [];
                $this->collect($msg, $refs, $written);
                $usage = self::total($usage, $msg, (string) $call['model']);
                break;
            }
            $messages[] = ['role' => 'assistant', 'content' => $msg->content];
        }
        $numbered = [];
        foreach (array_values($refs) as $i => $ref) {
            $numbered['S' . ($i + 1)] = $ref;
        }
        return [
            'text' => self::brief(implode("\n", $written ?? $text)),
            'refs' => $numbered,
            'usage' => $usage,
            'stop' => (string) $msg->stopReason,
            'model' => $msg->model,
            'container' => $container,
        ];
    }

    public function structured(array $call): array
    {
        $msg = $this->send($call, $call['messages'], null, null, false);
        $usage = self::total(self::zero(), $msg, (string) $call['model']);
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
     * The text between the brief's marker lines, every complete part joined. A part whose end line never came was cut
     * short or never finished, so it is left out; empty when no part is complete.
     */
    public static function brief(string $text): string
    {
        $parts = [];
        $offset = 0;
        while (($start = strpos($text, Prompts::BRIEF_START, $offset)) !== false) {
            $from = $start + strlen(Prompts::BRIEF_START);
            $end = strpos($text, Prompts::BRIEF_END, $from);
            if ($end === false) {
                break;
            }
            $parts[] = trim(substr($text, $from, $end - $from));
            $offset = $end + strlen(Prompts::BRIEF_END);
        }
        return trim(implode("\n\n", array_filter($parts, static fn (string $p): bool => $p !== '')));
    }

    /**
     * One streamed request, its usage reported through the after hook from what the stream had told, even when the
     * stream then breaks. An overload or a rate limit before any event is sent once more, the before hook first.
     *
     * @param array<string, mixed> $call
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>>|null $tools
     */
    private function send(array $call, array $messages, ?array $tools, ?string $container, bool $cache): BetaMessage
    {
        $model = (string) $call['model'];
        $outputConfig = ['effort' => (string) ($call['effort'] ?? 'high')];
        if (is_array($call['schema'] ?? null)) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $call['schema']];
        }
        $params = [
            'maxTokens' => (int) $call['maxTokens'],
            'messages' => $messages,
            'model' => $model,
            'system' => (string) $call['system'],
            'thinking' => ['type' => 'adaptive'],
            'outputConfig' => $outputConfig,
            'requestOptions' => ['maxRetries' => 0],
        ];
        if ($cache) {
            $params['cacheControl'] = ['type' => 'ephemeral'];
        }
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
        for ($attempt = 0; ; $attempt++) {
            if (is_callable($call['before'] ?? null)) {
                ($call['before'])();
            }
            $accumulator = MessageAccumulator::forBetaMessages();
            $started = false;
            $broken = null;
            try {
                foreach ($this->client->beta->messages->createStream(...$params) as $event) {
                    $accumulator->accumulate($event);
                    $started = $started || $event instanceof BetaRawMessageStartEvent;
                }
            } catch (\Throwable $e) {
                $broken = $e;
            }
            if ($broken === null || $started || $attempt > 0 || !self::overloaded($broken)) {
                break;
            }
            self::wait(self::retryAfter($broken));
        }
        $msg = null;
        if ($started) {
            try {
                $msg = $accumulator->message();
            } catch (\Throwable $e) {
                $broken ??= $e;
            }
        }
        if ($msg instanceof BetaMessage && is_callable($call['after'] ?? null)) {
            foreach (self::usage($msg, $model, $broken !== null || !$accumulator->isComplete()) as $served => $part) {
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

    /** An answer that says the API is overloaded or the rate limit was hit: nothing was generated, so a resend bills nothing. */
    private static function overloaded(\Throwable $e): bool
    {
        return $e instanceof APIStatusException && (in_array($e->status, [429, 529], true) || $e->type === ErrorType::OVERLOADED_ERROR);
    }

    /** The seconds the API asks to wait (retry-after-ms, retry-after), else RETRY_WAIT; never over RETRY_WAIT_MAX. */
    private static function retryAfter(\Throwable $e): float
    {
        $response = $e instanceof APIStatusException ? $e->response : null;
        $ms = $response?->getHeaderLine('retry-after-ms') ?? '';
        $s = $response?->getHeaderLine('retry-after') ?? '';
        $wait = is_numeric($ms) ? (float) $ms / 1000 : (is_numeric($s) ? (float) $s : self::RETRY_WAIT);
        return min(self::RETRY_WAIT_MAX, max(0.0, $wait));
    }

    private static function wait(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1000000));
        }
    }

    /**
     * The one message of the call that writes the brief once the research can go no further: the research turn as it
     * was asked, what to do now, and then, as data, the research text so far and every address it found.
     *
     * @param list<array<string, mixed>> $messages the research call's messages
     * @param list<string> $text
     * @param array<string, array{url: string, title: string, via: string, page_age: ?string, cited: bool}> $refs
     */
    private static function writeUp(array $messages, array $text, array $refs): string
    {
        $turn = [];
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'user') {
                $turn[] = self::plain($m['content'] ?? '');
            }
        }
        $lines = [];
        foreach ($refs as $r) {
            $lines[] = '- ' . $r['url'] . ' | ' . ($r['title'] !== '' ? $r['title'] : '(no title)') . ' | '
                . ((string) ($r['page_age'] ?? '') !== '' ? $r['page_age'] : 'age unknown') . ' | ' . ($r['via'] === 'fetch' ? 'read' : 'search result');
        }
        $notes = trim(implode("\n", $text));
        return trim(implode("\n\n", $turn)) . "\n\n" . Prompts::WRITE_NOW
            . "\n\nThe research so far:\n" . ($notes !== '' ? $notes : '(none)')
            . "\n\nThe addresses it found, as address | title | page age | how it was found:\n" . ($lines !== [] ? implode("\n", $lines) : '(none)');
    }

    /** A message's content as text: a string, or its text blocks joined. */
    private static function plain(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        $out = [];
        foreach (is_array($content) ? $content : [] as $block) {
            if (is_array($block) && is_string($block['text'] ?? null)) {
                $out[] = $block['text'];
            }
        }
        return implode("\n\n", $out);
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
     * A stream cut short never sent its closing counts, so the results that had arrived are counted at least, and its
     * output at least its text and thinking at about four characters a token: a broken call is never taken as free.
     *
     * @return array<string, array<string, int>> model => usage
     */
    private static function usage(BetaMessage $msg, string $requested, bool $cut = false): array
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
        $searches = (int) ($u->serverToolUse?->webSearchRequests ?? 0);
        $fetches = (int) ($u->serverToolUse?->webFetchRequests ?? 0);
        if ($cut) {
            $chars = 0;
            $results = ['web_search_tool_result' => 0, 'web_fetch_tool_result' => 0];
            foreach ($msg->content as $block) {
                $chars += strlen(match ($block->type) {
                    'text' => $block->text,
                    'thinking' => $block->thinking,
                    default => '',
                });
                if (isset($results[$block->type])) {
                    $results[$block->type]++;
                }
            }
            $searches = max($searches, $results['web_search_tool_result']);
            $fetches = max($fetches, $results['web_fetch_tool_result']);
            $out[$served] ??= self::zero();
            $out[$served]['usage_out'] = max($out[$served]['usage_out'], intdiv($chars + 3, 4));
        }
        $out[$served] = self::add($out[$served] ?? self::zero(), ['searches' => $searches, 'fetches' => $fetches]);
        return $out;
    }

    /**
     * $usage plus one response's, every model's share together.
     *
     * @param array<string, int> $usage
     * @return array<string, int>
     */
    private static function total(array $usage, BetaMessage $msg, string $requested): array
    {
        foreach (self::usage($msg, $requested) as $part) {
            $usage = self::add($usage, $part);
        }
        return $usage;
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
