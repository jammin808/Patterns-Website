<?php

declare(strict_types=1);

namespace Psc\News;

/**
 * The desk's model calls: the seam the pipeline is tested through (tests/Fakes/FakeNewsModel.php) and that
 * AnthropicNewsModel fills with the Anthropic API.
 *
 * A call is an array: model, system (text), messages (the API's shape), maxTokens, effort, and optionally tools (the
 * server tools, SDK-shaped: web_search and web_fetch with maxUses, blockedDomains ...) and schema (a JSON schema the
 * answer must follow); a message's content is a string or text blocks, a block marked for the cache with cacheControl.
 * Two hooks keep the pipeline in charge of the money: before (callable(): void) runs before every request the call
 * makes, a research call's continuations and a resend included, and throws to stop it; after
 * (callable(array $usage, string $model): void) runs after every response with that response's usage and the model
 * that served it, so the spend is recorded even when a later request fails, and for a stream that broke what it had
 * reported by then.
 *
 * Usage is an array of usage_in, usage_out, cache_write, cache_read, searches and fetches (never "tokens": the
 * log masks keys that look like secrets).
 */
interface NewsModel
{
    /**
     * A research call with the web tools: it runs until the model ends its turn, resuming a paused one as it is; once
     * the tools' allowance (or the continuations) are used up, a last call without tools writes the brief from the
     * research so far.
     *
     * @param array<string, mixed> $call
     * @return array{
     *     text: string,
     *     refs: array<string, array{url: string, title: string, via: string, page_age: ?string, cited: bool}>,
     *     usage: array<string, int>,
     *     stop: string,
     *     model: string,
     *     container: ?string
     * } text is the brief (each part between both marker lines, joined; a part without its end line is left out); refs
     *   are numbered S1, S2 ... in the order the research first saw each address; stop is the last response's
     */
    public function research(array $call): array;

    /**
     * A call without tools whose answer follows $call['schema'].
     *
     * @param array<string, mixed> $call
     * @return array{data: ?array<string, mixed>, usage: array<string, int>, stop: string, model: string} data is
     *   null after a refusal, a max_tokens stop or an answer that is not the JSON asked for
     */
    public function structured(array $call): array;
}
