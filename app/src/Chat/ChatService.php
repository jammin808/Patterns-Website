<?php

declare(strict_types=1);

namespace Psc\Chat;

/**
 * One visitor message through the assistant:
 *   1. the scope check (gate model, structured verdict) on the latest message with recent context;
 *      out of scope or abusive → a fixed reply, and the answer model is never called;
 *      a failed check fails closed (an "unavailable" reply with the contact routes);
 *   2. the answer (answer model, streamed) from the stored history, with the page the visitor is on as a
 *      mid-conversation system note;
 *   3. both messages, the verdict and the token counts stored; usage counted for the daily budget.
 * Events go to $emit(name, data): meta, delta, done, error.
 */
final class ChatService
{
    public function __construct(
        private readonly ModelGateway $gateway,
        private readonly ChatStore $store,
        private readonly Knowledge $knowledge,
        private readonly string $siteName,
        private readonly string $baseUrl,
        private readonly int $historyMessages = 12,
        private readonly ?\Closure $log = null,
    ) {
    }

    /**
     * @param callable(string, array<string, mixed>): void $emit
     * @param callable(): bool $aborted true once the visitor has gone
     */
    public function reply(string $conversationId, string $message, string $page, callable $emit, callable $aborted): void
    {
        $history = $this->store->history($conversationId, $this->historyMessages);
        $this->store->add($conversationId, 'user', $message);

        try {
            $gate = $this->gateway->classify(Prompts::gateSystem(), $this->gateMessages($history, $message), Prompts::gateSchema());
        } catch (\Throwable $e) {
            $this->fail('gate', $e);
            $this->finishFixed($conversationId, Prompts::UNAVAILABLE, 'error', $emit, 'unavailable', true);
            return;
        }
        $this->store->addUsage($gate['tokens_in'], $gate['tokens_out']);
        $verdict = $gate['refused'] ? 'abuse' : (string) ($gate['data']['verdict'] ?? '');
        if (!in_array($verdict, ['in_scope', 'needs_human', 'code_or_internals', 'out_of_scope', 'abuse'], true)) {
            $this->finishFixed($conversationId, Prompts::UNAVAILABLE, 'error', $emit, 'unavailable', true);
            return;
        }
        if ($verdict === 'out_of_scope') {
            $this->finishFixed($conversationId, Prompts::OUT_OF_SCOPE, $verdict, $emit, 'out', false, $gate['tokens_in'], $gate['tokens_out']);
            return;
        }
        if ($verdict === 'code_or_internals') {
            $this->finishFixed($conversationId, Prompts::NO_CODE, $verdict, $emit, 'out', false, $gate['tokens_in'], $gate['tokens_out']);
            return;
        }
        if ($verdict === 'abuse') {
            $this->finishFixed($conversationId, Prompts::ABUSE, $verdict, $emit, 'out', false, $gate['tokens_in'], $gate['tokens_out']);
            return;
        }

        $system = [[
            'type' => 'text',
            'text' => Prompts::answerSystem($this->knowledge->text(), $this->siteName, $this->baseUrl),
            'cacheControl' => ['type' => 'ephemeral'],
        ]];
        $messages = self::alternate(array_merge($history, [['role' => 'user', 'content' => $message]]));
        if ($page !== '') {
            $messages[] = ['role' => 'system', 'content' => 'The visitor is reading this page of the site: ' . $page . ($verdict === 'needs_human' ? '. This request needs the developer personally: answer briefly and point to the right contact route.' : '')];
        } elseif ($verdict === 'needs_human') {
            $messages[] = ['role' => 'system', 'content' => 'This request needs the developer personally: answer briefly and point to the right contact route.'];
        }

        $sent = 0;
        $answer = '';
        try {
            $result = $this->gateway->stream($system, $messages, function (string $text) use ($emit, $aborted, &$sent, &$answer): void {
                if ($aborted()) {
                    throw new StreamAborted();
                }
                $answer .= $text;
                if (self::looksLikeCode($answer)) {
                    throw new CodeGuardTripped();
                }
                $sent += strlen($text);
                $emit('delta', ['t' => $text]);
            });
        } catch (StreamAborted) {
            return;
        } catch (CodeGuardTripped) {
            // The answer started to contain code or commands: it is withdrawn and replaced, whatever was shown.
            $emit('replace', ['t' => Prompts::NO_CODE]);
            $this->store->add($conversationId, 'assistant', Prompts::NO_CODE, 'code_guard', $gate['tokens_in'], $gate['tokens_out']);
            $emit('done', ['scope' => 'out', 'handoff' => false, 'truncated' => false]);
            return;
        } catch (\Throwable $e) {
            $this->fail('answer', $e);
            if ($sent > 0) {
                $emit('error', ['code' => 'interrupted', 'message' => 'The answer was cut off. Please ask again, or use the contact form.']);
                return;
            }
            $this->finishFixed($conversationId, Prompts::UNAVAILABLE, 'error', $emit, 'unavailable', true);
            return;
        }

        $this->store->addUsage($result['tokens_in'], $result['tokens_out']);
        if ($result['stop'] === 'refusal' || trim($result['text']) === '') {
            $emit('error', ['code' => 'declined', 'message' => "I can't answer that one here. Please use the contact form and the developer will help."]);
            $this->store->add($conversationId, 'assistant', Prompts::UNAVAILABLE, 'refusal', $result['tokens_in'], $result['tokens_out']);
            return;
        }
        $this->store->add($conversationId, 'assistant', $result['text'], $verdict, $result['tokens_in'] + $gate['tokens_in'], $result['tokens_out'] + $gate['tokens_out']);
        $emit('done', [
            'scope' => $verdict === 'needs_human' ? 'human' : 'in',
            'handoff' => $verdict === 'needs_human',
            'truncated' => $result['stop'] === 'max_tokens',
        ]);
    }

    /**
     * True when text contains code or command lines the assistant must never give: a fenced block, an indented or
     * prompt-prefixed command, or a known build/install/package/scripting command. Plain product words never trip it.
     */
    public static function looksLikeCode(string $text): bool
    {
        if (str_contains($text, '```') || str_contains($text, '~~~')) {
            return true;
        }
        $patterns = [
            // A shell prompt at the start of a line: "$ ", "PS C:\>", "C:\Users>", ">>>".
            '/^\s*(\$\s|PS\s?[A-Z]:\\\\[^>\n]*>|PS>|[A-Z]:\\\\[^>\n]*>|>>>)\s*\S/m',
            // Build, install, package and scripting commands.
            '/\b(dotnet|npm|npx|yarn|pnpm|pip3?|composer|git|winget|choco|scoop|msiexec|cmake|msbuild|nuget|docker|curl|wget|iwr|Invoke-WebRequest|Install-Module|Install-Package|Set-ExecutionPolicy|Start-Process|New-Item|Get-ChildItem|Remove-Item|reg\s+add|sc\s+create|netsh|sudo|apt-get|brew)\s+(-{1,2}[a-z]|\/[a-z]|install|build|run|restore|publish|clone|ci|add|test|pull|exec|start|http|[A-Z][\w.-]+)/',
            '/\b(powershell|pwsh|cmd)(\.exe)?\s+(-|\/)[a-z]/i',
            // Source and project files.
            '/\.(ps1|psm1|sh|csproj|sln|cs|ts|tsx|php|py|xaml)\b(?![\w-])/i',
            // Language syntax.
            '/<\?php|#include\s*<|\bfunction\s+\w+\s*\([^)]*\)\s*\{|\b(public|private|protected)\s+(static\s+)?(class|void|function|async|readonly)\b|\bconst\s+\w+\s*=\s*\S|=>\s*\{|^\s*(import\s+[\w{*][^\n]*\s+from\s+|using\s+[A-Z][\w.]+;|namespace\s+[A-Z]\w*)/m',
            // Indented lines ending like code.
            '/^\s{4,}\S.*[;{}]\s*$/m',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $text)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The gate sees the last few turns (for follow-ups like "how do I do that?") and the new message, marked.
     *
     * @param list<array{role: string, content: string}> $history
     * @return list<array{role: string, content: string}>
     */
    private function gateMessages(array $history, string $message): array
    {
        $context = [];
        foreach (array_slice($history, -4) as $h) {
            $context[] = strtoupper($h['role']) . ': ' . mb_substr($h['content'], 0, 600);
        }
        $text = ($context !== [] ? "Earlier turns (context only):\n" . implode("\n", $context) . "\n\n" : '')
            . "LATEST VISITOR MESSAGE (classify this):\n<<<\n" . $message . "\n>>>";
        return [['role' => 'user', 'content' => $text]];
    }

    /** @param callable(string, array<string, mixed>): void $emit */
    private function finishFixed(string $id, string $text, string $verdict, callable $emit, string $scope, bool $handoff, int $in = 0, int $out = 0): void
    {
        $emit('delta', ['t' => $text]);
        $this->store->add($id, 'assistant', $text, $verdict, $in, $out);
        $emit('done', ['scope' => $scope, 'handoff' => $handoff, 'truncated' => false]);
    }

    /**
     * Merges consecutive same-role messages (a turn whose answer failed leaves two user messages in a row).
     *
     * @param list<array{role: string, content: string}> $messages
     * @return list<array{role: string, content: string}>
     */
    public static function alternate(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last]['role'] === $m['role']) {
                $out[$last]['content'] .= "\n\n" . $m['content'];
            } else {
                $out[] = $m;
            }
        }
        while ($out !== [] && $out[0]['role'] !== 'user') {
            array_shift($out);
        }
        return $out;
    }

    private function fail(string $where, \Throwable $e): void
    {
        if ($this->log !== null) {
            ($this->log)($where, $e);
        }
    }
}
