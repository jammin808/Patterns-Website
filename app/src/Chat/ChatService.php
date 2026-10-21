<?php

declare(strict_types=1);

namespace Psc\Chat;

/**
 * One visitor message through the assistant:
 *   1. the scope check (gate model, structured verdict) on the latest message with recent context;
 *      out of scope or abusive → a fixed reply, and the answer model is never called;
 *      a failed check fails closed (an "unavailable" reply with the contact routes);
 *   2. the answer (answer model, streamed) from the stored history, less every exchange that was refused or never
 *      ruled on, so a declined instruction never reaches the answer model in a later turn; the page the visitor is
 *      on goes in as a mid-conversation system note made from the site's own title for it, never from their words;
 *   3. both messages, the verdict and the token counts stored; usage counted for the daily budget on every path,
 *      a withdrawn or cut-off answer included.
 * The answer is released a line at a time and only after the code guard has passed everything received, so a code
 * line split across fragments is never sent. Events go to $emit(name, data): meta, delta, replace, done, error.
 */
final class ChatService
{
    /**
     * Stored verdicts of an exchange the answer model must not see again: the gate refused it, the guard withdrew
     * the answer, the model declined, or the check failed (so the gate never ruled on the visitor's text).
     */
    public const UNANSWERED = ['out_of_scope', 'code_or_internals', 'abuse', 'code_guard', 'refusal', 'error'];

    /** A line longer than this that is plainly prose is released in part, so a long paragraph still streams. */
    private const LONG_LINE = 300;

    /** What a long line keeps back when part of it is released: room for a command still arriving. */
    private const HOLD_BACK = 40;

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
     * @param array{path: string, title: string}|null $page the page the visitor is on, as the site names it
     * @param callable(string, array<string, mixed>): void $emit
     * @param callable(): bool $aborted true once the visitor has gone
     */
    public function reply(string $conversationId, string $message, ?array $page, callable $emit, callable $aborted): void
    {
        $history = $this->store->history($conversationId, $this->historyMessages);
        $this->store->add($conversationId, 'user', $message);

        try {
            $gate = $this->gateway->classify(Prompts::gateSystem(), self::gateMessages($history, $message), Prompts::gateSchema());
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
        $messages = self::alternate(array_merge(self::answerHistory($history), [['role' => 'user', 'content' => $message]]));
        $note = self::pageNote($page, $verdict === 'needs_human');
        if ($note !== '') {
            $messages[] = ['role' => 'system', 'content' => $note];
        }

        $answer = '';
        $pending = '';
        try {
            $result = $this->gateway->stream($system, $messages, function (string $text) use ($emit, $aborted, &$answer, &$pending): void {
                if ($aborted()) {
                    throw new StreamAborted();
                }
                $answer .= $text;
                if (self::withheld($answer)) {
                    throw new CodeGuardTripped();
                }
                $pending .= $text;
                $cut = self::releasable($pending);
                if ($cut > 0) {
                    $emit('delta', ['t' => substr($pending, 0, $cut)]);
                    $pending = substr($pending, $cut);
                }
            });
            if (self::withheld($result['text'])) {
                throw new CodeGuardTripped();
            }
        } catch (StreamAborted) {
            $this->countAnswerUsage();
            return;
        } catch (CodeGuardTripped) {
            // The answer started to contain code, commands or the instructions: it is withdrawn and replaced, and
            // nothing after the last line the guard passed was ever sent.
            $usage = $this->countAnswerUsage();
            $emit('replace', ['t' => Prompts::NO_CODE]);
            $this->store->add($conversationId, 'assistant', Prompts::NO_CODE, 'code_guard', $usage['tokens_in'] + $gate['tokens_in'], $usage['tokens_out'] + $gate['tokens_out']);
            $emit('done', ['scope' => 'out', 'handoff' => false, 'truncated' => false]);
            return;
        } catch (\Throwable $e) {
            $this->fail('answer', $e);
            $this->countAnswerUsage();
            if ($answer !== '') {
                // What arrived before the fault passed the guard line by line; the visitor keeps it.
                if ($pending !== '') {
                    $emit('delta', ['t' => $pending]);
                }
                $emit('error', ['code' => 'interrupted', 'message' => 'The answer was cut off. Please ask again, or use the contact form.']);
                return;
            }
            $this->finishFixed($conversationId, Prompts::UNAVAILABLE, 'error', $emit, 'unavailable', true);
            return;
        }

        $this->store->addUsage($result['tokens_in'], $result['tokens_out'], $result['cache_write'], $result['cache_read']);
        if ($result['stop'] === 'refusal' || trim($result['text']) === '') {
            $emit('error', ['code' => 'declined', 'message' => "I can't answer that one here. Please use the contact form and the developer will help."]);
            $this->store->add($conversationId, 'assistant', Prompts::UNAVAILABLE, 'refusal', $result['tokens_in'], $result['tokens_out']);
            return;
        }
        if ($pending !== '') {
            $emit('delta', ['t' => $pending]);
        }
        $this->store->add($conversationId, 'assistant', $result['text'], $verdict, $result['tokens_in'] + $gate['tokens_in'], $result['tokens_out'] + $gate['tokens_out']);
        $emit('done', [
            'scope' => $verdict === 'needs_human' ? 'human' : 'in',
            'handoff' => $verdict === 'needs_human',
            'truncated' => $result['stop'] === 'max_tokens',
        ]);
    }

    /**
     * Counts what an answer cut short (withdrawn, abandoned or failed) spent so far, cached prompt included.
     *
     * @return array{tokens_in: int, tokens_out: int, cache_write: int, cache_read: int}
     */
    private function countAnswerUsage(): array
    {
        $u = $this->gateway->lastUsage();
        if ($u['tokens_in'] + $u['tokens_out'] + $u['cache_write'] + $u['cache_read'] > 0) {
            $this->store->addUsage($u['tokens_in'], $u['tokens_out'], $u['cache_write'], $u['cache_read']);
        }
        return $u;
    }

    /**
     * How many leading bytes of the text held back may be sent: everything up to its last line break, or, for a
     * long line of plain prose, up to a space short of its end. A line that starts indented or like a prompt waits
     * for its line break, because that is when the guard can judge it.
     */
    public static function releasable(string $pending): int
    {
        $nl = strrpos($pending, "\n");
        if ($nl !== false) {
            return $nl + 1;
        }
        if (strlen($pending) <= self::LONG_LINE || preg_match('/^(\s|\$|>|PS\b|[A-Z]:\\\\)/', $pending)) {
            return 0;
        }
        $space = strrpos(substr($pending, 0, strlen($pending) - self::HOLD_BACK), ' ');
        return $space === false ? 0 : $space + 1;
    }

    /** True when an answer must be withdrawn: it holds code or commands, or it quotes the assistant's instructions. */
    public static function withheld(string $text): bool
    {
        return self::looksLikeCode($text) || self::quotesInstructions($text);
    }

    /** True when text repeats the answer model's own instructions (Prompts::CANARIES). */
    public static function quotesInstructions(string $text): bool
    {
        foreach (Prompts::CANARIES as $canary) {
            if (preg_match($canary, $text)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The system note about the page the visitor is on, built from the site's own title and path for it.
     *
     * @param array{path: string, title: string}|null $page
     */
    public static function pageNote(?array $page, bool $needsHuman): string
    {
        $parts = [];
        if ($page !== null && $page['path'] !== '') {
            $parts[] = 'The visitor is reading: ' . $page['title'] . ' (' . $page['path'] . ').';
        }
        if ($needsHuman) {
            $parts[] = 'This request needs the developer personally: answer briefly and point to the right contact route.';
        }
        return implode(' ', $parts);
    }

    /**
     * The history the answer model sees: every exchange in UNANSWERED is left out, the visitor message with the
     * reply that refused it, so text the gate turned away cannot ride along with a later, harmless message.
     *
     * @param list<array{role: string, content: string, verdict?: string|null}> $history
     * @return list<array{role: string, content: string}>
     */
    public static function answerHistory(array $history): array
    {
        $out = [];
        foreach ($history as $h) {
            if ($h['role'] === 'assistant' && in_array($h['verdict'] ?? null, self::UNANSWERED, true)) {
                if ($out !== [] && $out[count($out) - 1]['role'] === 'user') {
                    array_pop($out);
                }
                continue;
            }
            $out[] = ['role' => $h['role'], 'content' => $h['content']];
        }
        return $out;
    }

    /**
     * True when text contains code or command lines the assistant must never give: a fenced block, an indented or
     * prompt-prefixed command, a known build, install, package, registry or scripting command, or a fragment of a
     * program, script, query or settings file. Command words are matched case-sensitively, as typed at a prompt,
     * so prose ("Curl the cable", "the composer of the score") never trips it; only tool names that are never
     * English words are matched in any case.
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
            '/\b(dotnet|npm|npx|yarn|pnpm|pip3?|composer|git|winget|choco|scoop|msiexec|cmake|msbuild|nuget|docker|curl|wget|iwr|Invoke-WebRequest|Install-Module|Install-Package|Set-ExecutionPolicy|Start-Process|New-Item|Get-ChildItem|Remove-Item|reg\s+add|sc\s+create|netsh|sudo|apt-get|brew)\s+(-{1,2}[A-Za-z]|\/[a-z]|install|build|run|restore|publish|clone|ci|add|test|pull|exec|start|http|[A-Z][\w.-]+)/',
            // Tool names that are never prose, in any case, with a subcommand or switch: "DOTNET BUILD".
            '/\b(dotnet|msiexec|winget|msbuild|nuget)\s+(-{1,2}[a-z]|\/[a-z]|build|run|publish|restore|test|new|add|tool|pack|clean|install|upgrade|uninstall|search|show|source|list|push|exec|watch)\b/i',
            '/\b(powershell|pwsh|cmd)(\.exe)?\s+(-|\/)[a-z]/i',
            // A PowerShell cmdlet with a parameter: "Unblock-File -Path", "New-NetFirewallRule -DisplayName".
            '/\b[A-Z][a-z]+-[A-Z][A-Za-z]+\s+-[A-Za-z]/',
            // Download-and-run one-liners.
            '/\b(iex|irm)\b|\|\s*iex\b/',
            // The registry.
            '/HKLM:|HKCU:|HKEY_[A-Z_]+|Windows Registry Editor/',
            // Media tools with switches.
            '/\b(ffmpeg|ffprobe)\s+-{1,2}[a-z]/i',
            // Batch files.
            '/^\s*@echo\s+off\b|^\s*set\s+\w+=/mi',
            // Version control, the firewall and short package installs.
            '/\bgit\s+(checkout|commit|push|fetch|reset|merge|rebase|status|log)\b/',
            '/\bnetsh\s+\w+/i',
            '/\bnpm\s+i\b/',
            // Source and project files.
            '/\.(ps1|psm1|sh|csproj|sln|cs|ts|tsx|php|py|xaml)\b(?![\w-])/i',
            // Language syntax.
            '/<\?php|#include\s*<|\bfunction\s+\w+\s*\([^)]*\)\s*\{|\b(public|private|protected)\s+(static\s+)?(class|void|function|async|readonly)\b|\bconst\s+\w+\s*=\s*\S|=>\s*\{|^\s*(import\s+[\w{*][^\n]*\s+from\s+|using\s+[A-Z][\w.]+;|namespace\s+[A-Z]\w*)/m',
            // Python imports at the start of a line.
            '/^\s*(import\s+\w+|from\s+\w+\s+import)\b/m',
            // Statements: a declaration or call ending in a semicolon, such as "var ok = Gate.Verify(id);".
            '/^\s*(var|let|const|int|string|bool|await|return)\s+[\w.]+\s*(=|\()[^\n]*;\s*$/m',
            '/\b[A-Z]\w*\.[A-Z]\w*\([^)\n]*\)\s*;/',
            // SQL.
            '/\b(SELECT\s+.+\s+FROM|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DROP\s+TABLE)\b/',
            // A JSON object over two lines or more: a brace, then a quoted key on the next line.
            '/\{\s*\n\s*"[^"\n]*"\s*:/',
            // An OSC address with an argument: "/stagewatch/next 1", "/video/text s". A site link (ending in a
            // slash, or inside a Markdown link) is not one.
            '#(?<![\w./(\]-])/[a-z][\w-]*(?:/[\w-]+)++(?![/\w-])\s+(?:-?\d|"|\'|[ifsTF]\b)#',
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
     * The gate sees the last few turns (for follow-ups like "how do I do that?") and the new message. Every turn is
     * a JSON string, so nothing a visitor types can close the frame or forge another turn or another "latest
     * message": quotes and line breaks inside it arrive escaped.
     *
     * @param list<array{role: string, content: string, verdict?: string|null}> $history
     * @return list<array{role: string, content: string}>
     */
    public static function gateMessages(array $history, string $message): array
    {
        $json = static fn (string $s): string => (string) json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $context = [];
        foreach (array_slice($history, -4) as $h) {
            $context[] = ($h['role'] === 'assistant' ? 'ASSISTANT' : 'VISITOR') . ': ' . $json(mb_substr($h['content'], 0, 600));
        }
        $text = ($context !== [] ? "Earlier turns (context only, each a JSON string):\n" . implode("\n", $context) . "\n\n" : '')
            . "LATEST VISITOR MESSAGE (JSON string):\n" . $json($message);
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
