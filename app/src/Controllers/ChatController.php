<?php

declare(strict_types=1);

namespace Psc\Controllers;

use Psc\Chat\AnthropicGateway;
use Psc\Chat\ChatService;
use Psc\Chat\ChatStore;
use Psc\Chat\Knowledge;
use Psc\Chat\ModelGateway;
use Psc\Http\Request;
use Psc\Http\Response;

/**
 * POST /api/chat {message, page} → a Server-Sent Events stream (meta, delta…, done | error).
 * Refused before the stream starts, as JSON: 400 invalid, 429 rate_limited / turn_limit, 503 unavailable / busy.
 * The conversation belongs to the visitor's session; its id never comes from the browser.
 */
final class ChatController extends Controller
{
    private static ?ModelGateway $gatewayOverride = null;

    /** Tests swap the model calls for a fake. */
    public static function useGateway(?ModelGateway $gateway): void
    {
        self::$gatewayOverride = $gateway;
    }

    public function message(Request $request): Response
    {
        $cfg = $this->app->config;
        $gateway = $this->gateway();
        if ($gateway === null) {
            return Response::json(['error' => 'unavailable', 'message' => 'The assistant is not available right now.'], 503);
        }
        $body = $request->json();
        $message = is_string($body['message'] ?? null) ? self::clean($body['message']) : '';
        $page = is_string($body['page'] ?? null) ? self::cleanPath($body['page']) : '';
        $max = $cfg->int('chat.max_input_chars', 1200);
        if ($message === '') {
            return Response::json(['error' => 'invalid', 'message' => 'Type a question first.'], 400);
        }
        if (mb_strlen($message) > $max) {
            return Response::json(['error' => 'invalid', 'message' => "Please keep questions under {$max} characters."], 400);
        }

        $ip = $this->app->clientIp();
        $limiter = $this->app->limiter();
        if (!$limiter->hit('chat-ip-10m:' . $ip, $cfg->int('chat.per_ip_per_10min', 20), 600)
            || !$limiter->hit('chat-ip-day:' . $ip, $cfg->int('chat.per_ip_per_day', 120), 86400)) {
            return Response::json(['error' => 'rate_limited', 'message' => 'You have sent a lot of questions in a short time. Please wait a few minutes, or use the contact form.'], 429)
                ->header('Retry-After', '600');
        }

        $store = new ChatStore($this->app->db());
        if ($store->usageToday() >= $cfg->int('chat.daily_token_budget', 1500000)) {
            return Response::json(['error' => 'busy', 'message' => 'The assistant is very busy today. Please use the contact form and the developer will reply.'], 503);
        }

        $session = $this->app->session();
        $id = (string) $session->get('chat_id', '');
        $conversation = $id !== '' ? $store->find($id) : null;
        if ($conversation === null) {
            $id = ChatStore::newId();
            $store->create($id, $this->app->auth()->id(), $this->app->ipHash($ip), $page);
            $session->set('chat_id', $id);
            $conversation = ['turns' => 0];
        }
        $turns = (int) $conversation['turns'];
        $maxTurns = $cfg->int('chat.max_turns', 16);
        if ($turns >= $maxTurns) {
            return Response::json(['error' => 'turn_limit', 'message' => 'This conversation has reached its length limit. Start a new one, or use the contact form for anything detailed.'], 429);
        }
        $session->close();

        $service = new ChatService(
            $gateway,
            $store,
            new Knowledge($this->app->root, $this->app->storage->dir('cache')),
            $this->app->name(),
            $this->app->baseUrl(),
            $cfg->int('chat.history_messages', 12),
            fn (string $where, \Throwable $e) => $this->app->log->exception($e, 'chat ' . $where),
        );
        $timeout = $cfg->int('chat.timeout_seconds', 60);

        $writer = static function () use ($service, $id, $message, $page, $turns, $maxTurns, $timeout): void {
            if (PHP_SAPI !== 'cli') {
                @set_time_limit($timeout + 30);
            }
            ignore_user_abort(true);
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', '0');
            @ini_set('implicit_flush', '1');
            if (PHP_SAPI !== 'cli') {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }
            $emit = static function (string $event, array $data): void {
                echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                @flush();
            };
            // A comment line large enough to push the headers and first bytes through any buffering proxy.
            echo ':' . str_repeat(' ', 2048) . "\n\n";
            @flush();
            $emit('meta', ['conversation' => $id, 'turn' => $turns + 1, 'max_turns' => $maxTurns]);
            $service->reply($id, $message, $page, $emit, static fn (): bool => connection_aborted() === 1);
        };

        return Response::stream($writer, 'text/event-stream; charset=utf-8')
            ->header('Cache-Control', 'no-cache, no-store, no-transform')
            ->header('X-Accel-Buffering', 'no')
            ->header('X-LiteSpeed-Cache-Control', 'no-cache');
    }

    /** POST /api/chat/reset: the next message starts a new conversation. */
    public function reset(Request $request): Response
    {
        $this->app->session()->forget('chat_id');
        return Response::json(['ok' => true]);
    }

    /** POST /api/chat/feedback {rating: up|down, note?}: a thumbs up or down on the latest answer. */
    public function feedback(Request $request): Response
    {
        $id = (string) $this->app->session()->get('chat_id', '');
        $body = $request->json();
        $rating = $body['rating'] ?? '';
        if ($id === '' || !in_array($rating, ['up', 'down'], true)) {
            return Response::json(['error' => 'invalid'], 400);
        }
        if (!$this->app->limiter()->hit('chat-fb:' . $id, 20, 3600)) {
            return Response::json(['error' => 'rate_limited'], 429);
        }
        $note = is_string($body['note'] ?? null) ? mb_substr(self::clean($body['note']), 0, 500) : '';
        $store = new ChatStore($this->app->db());
        if ($store->find($id) === null) {
            return Response::json(['error' => 'invalid'], 400);
        }
        $store->add($id, 'feedback', $rating . ($note !== '' ? ': ' . $note : ''));
        return Response::json(['ok' => true]);
    }

    private function gateway(): ?ModelGateway
    {
        if (self::$gatewayOverride !== null) {
            return self::$gatewayOverride;
        }
        $cfg = $this->app->config;
        $key = $cfg->string('chat.api_key');
        if (!$cfg->bool('chat.enabled', true) || $key === '' || !AnthropicGateway::available()) {
            return null;
        }
        return new AnthropicGateway(
            $key,
            $cfg->string('chat.model', 'claude-sonnet-5-5'),
            $cfg->string('chat.gate_model', 'claude-haiku-5-5'),
            $cfg->int('chat.max_tokens', 1200),
            (float) $cfg->int('chat.timeout_seconds', 60),
        );
    }

    /** Trims, drops control characters (keeping newlines) and collapses runs of blank lines. */
    public static function clean(string $s): string
    {
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        $s = str_replace("\r\n", "\n", $s);
        $s = (string) preg_replace("/\n{3,}/", "\n\n", $s);
        return trim($s);
    }

    private static function cleanPath(string $p): string
    {
        $p = (string) parse_url($p, PHP_URL_PATH);
        return preg_match('#^/[a-z0-9/_-]{0,120}$#', $p) ? $p : '';
    }
}
