<?php
// app/Services/GeminiClient.php
// Extracted from AIController.php (Sept 2026) — callGemini() was
// identical logic shared by 3 unrelated AI features (material rec,
// design description, analytics) living inside one 621-line controller.
// Body unchanged from the original, moved verbatim.
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiClient
{
    // DSA: round-robin O(1) — tries key_1, key_2, key_3 on 429
    public function call(string $prompt, int $maxTokens = 1500, ?array $jsonSchema = null): ?string
    {
        $primary  = (string) config('services.gemini.model', 'gemini-3.6-flash');
        $fallback = (string) config('services.gemini.fallback_model', 'gemini-3.6-flash');
        $started  = microtime(true);

        $text = $this->callModel($primary, $prompt, $maxTokens, $jsonSchema);
        if ($text === null && $fallback !== '' && $fallback !== $primary && (microtime(true) - $started) < 30) {
            Log::warning('Gemini: primary model failed, trying fallback', ['primary' => $primary, 'fallback' => $fallback]);
            $text = $this->callModel($fallback, $prompt, $maxTokens, $jsonSchema);
        }
        return $text;
    }

    private function callModel(string $model, string $prompt, int $maxTokens, ?array $jsonSchema): ?string
    {
        // GEMINI_KEY_COUNT used to gate this loop directly — if it's unset
        // or wrong on a given deploy (defaults to 1), key_2/key_3 are never
        // tried even when configured. Derive the count from which keys are
        // actually non-empty instead, so rotation can't silently degrade
        // to one key from a missing/stale env var.
        $keys = array_filter([
            1 => config('services.gemini.key_1'),
            2 => config('services.gemini.key_2'),
            3 => config('services.gemini.key_3'),
        ]);
        $timeout = max(5, (int) config('services.gemini.timeout', 30));
        // Bounded retry on the same key for transient provider trouble (503/504 overload, a dropped connection or timeout).
        // A 500 or a 4xx request error fails the same way on every attempt, so it is not retried.
        $retries = max(0, min(2, (int) config('services.gemini.retries', 1)));
        // One overall deadline across keys and retries, kept under the browser's 120 s wait: a slow provider ends in the caller's
        // fallback instead of a request the customer has already given up on.
        $deadline = microtime(true) + max(10, (int) config('services.gemini.budget', 75));
        $left     = fn () => (int) floor($deadline - microtime(true));

        if (!$keys) {
            Log::error('Gemini: no API keys configured');
            return null;
        }

        foreach ($keys as $keyNum => $key) {
            if ($left() < 5) { Log::warning('Gemini: time budget spent, stopping'); return null; }

            // Key goes in a header, not the query string, so it never lands in URL/access logs.
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

            try {
                $generationConfig = [
                        'temperature'     => 0.3,
                        'maxOutputTokens' => $maxTokens,
                        // BUG-008 FIX (confirmed against Google's current docs,
                        // Aug 2026): gemini-3.x models (current GEMINI_MODEL =
                        // gemini-3.6-flash) think by default and CANNOT be
                        // fully disabled — "Gemini 3 Flash and Flash-Lite also
                        // do not support full thinking-off." Without this,
                        // thinking tokens were silently consuming the entire
                        // maxOutputTokens budget before any real answer text
                        // came out — matches the logged raw fragments exactly
                        // (e.g. raw="{\n  \"selections\":" — cut off after a
                        // handful of tokens). 'minimal' is the lowest level
                        // 3.x supports; this task is a simple catalog-selection
                        // classification with no need for deep reasoning.
                        // Do NOT add the legacy 'thinkingBudget' alongside
                        // this — 3.x models reject requests sending both.
                        'thinkingConfig'  => ['thinkingLevel' => 'minimal'],
                ];
                if ($jsonSchema !== null) {
                    $generationConfig['responseMimeType']   = 'application/json';
                    $generationConfig['responseJsonSchema'] = $jsonSchema;
                }

                $body = [
                    'contents'         => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => $generationConfig,
                    'safetySettings'   => [
                        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
                    ],
                ];
                for ($attempt = 0; ; $attempt++) {
                    try {
                        $response = Http::withHeaders(['x-goog-api-key' => $key])->timeout(max(1, min($timeout, $left())))->post($url, $body);
                    } catch (\Illuminate\Http\Client\ConnectionException $e) {
                        if ($attempt >= $retries || $left() < 5) { throw $e; }
                        Log::warning('Gemini connection failed, retrying', ['key' => $keyNum, 'attempt' => $attempt + 1]);
                        usleep(400000 * (2 ** $attempt));
                        continue;
                    }
                    if (!in_array($response->status(), [503, 504], true) || $attempt >= $retries || $left() < 5) { break; }
                    Log::warning('Gemini overloaded, retrying', ['key' => $keyNum, 'status' => $response->status(), 'attempt' => $attempt + 1]);
                    usleep(400000 * (2 ** $attempt));
                }

                // FIX: this used to only retry the next key on 429
                // (rate-limited). Any other failure — a bad/invalid key
                // (401/403), which is a real, distinct possibility here
                // since GEMINI_KEY_2/GEMINI_KEY_3 have a different string
                // format than GEMINI_KEY_1 and were never individually
                // verified — gave up immediately without ever trying the
                // remaining 2 keys. 401/403 are key-specific failures, just
                // like 429; a genuine 4xx request-shape error (400) or a
                // 5xx on Google's end would fail identically on every key,
                // so those still stop the loop rather than retry pointlessly.
                $reason = mb_substr((string) $response->json('error.message', ''), 0, 200);

                if (in_array($response->status(), [429, 401, 403])) {
                    Log::warning("Gemini key #{$keyNum} failed ({$response->status()}), trying next", [
                        'status' => $response->status(),
                        'reason' => $reason,
                    ]);
                    continue;
                }

                if (!$response->successful()) {
                    Log::error('Gemini error', ['status' => $response->status(), 'key' => $keyNum, 'reason' => $reason]);
                    return null;
                }

                // BUG-008 FIX: candidates[0].content.parts can hold MORE THAN
                // ONE part on thinking-enabled 3.x models — Google's own docs:
                // "the main result in a native Gemini response lives under
                // candidates[].content.parts" (plural). The old code always
                // read parts.0.text, which on a thinking model can be an
                // internal reasoning fragment rather than the final answer.
                // Concatenate every non-thought text part instead.
                $parts = $response->json('candidates.0.content.parts', []);
                $text  = collect($parts)
                    ->reject(fn($p) => $p['thought'] ?? false)
                    ->pluck('text')
                    ->filter()
                    ->implode('');

                // Diagnostic only (doesn't change control flow): confirms
                // whether a given failure was really the token budget running
                // out, next time this happens — usageMetadata.thoughtsTokenCount
                // shows exactly how much of maxOutputTokens thinking consumed.
                if ($response->json('candidates.0.finishReason') === 'MAX_TOKENS') {
                    Log::warning('Gemini hit MAX_TOKENS before finishing', [
                        'model'             => $model,
                        'key'               => $keyNum,
                        'requested_max'     => $maxTokens,
                        'thoughts_tokens'   => $response->json('usageMetadata.thoughtsTokenCount'),
                        'candidates_tokens' => $response->json('usageMetadata.candidatesTokenCount'),
                        'text_length'       => strlen($text),
                    ]);
                }

                return $text !== '' ? $text : null;

            } catch (\Throwable $e) {
                Log::error('Gemini exception', ['msg' => $e->getMessage(), 'type' => get_class($e), 'key' => $keyNum]);
                continue; // try next key rather than give up on one transient failure
            }
        }

        Log::error('Gemini: all keys exhausted');
        return null;
    }
}
