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
    public function call(string $prompt, int $maxTokens = 1500): ?string
    {
        $keyCount = (int) config('services.gemini.key_count', 1);
        $model    = config('services.gemini.model', 'gemini-1.5-flash');

        for ($i = 0; $i < $keyCount; $i++) {
            $keyNum = $i + 1;
            $key    = config("services.gemini.key_{$keyNum}");
            if (!$key) continue;

            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";

            try {
                $response = Http::timeout(30)->post($url, [
                    'contents'         => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
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
                    ],
                    'safetySettings'   => [
                        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
                    ],
                ]);

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
                if (in_array($response->status(), [429, 401, 403])) {
                    Log::warning("Gemini key #{$keyNum} failed ({$response->status()}), trying next", [
                        'status' => $response->status(),
                    ]);
                    continue;
                }

                if (!$response->successful()) {
                    Log::error('Gemini error', ['status' => $response->status(), 'key' => $keyNum]);
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

            } catch (\Exception $e) {
                Log::error('Gemini exception', ['msg' => $e->getMessage(), 'key' => $keyNum]);
                continue; // try next key rather than give up on one transient failure
            }
        }

        Log::error('Gemini: all keys exhausted');
        return null;
    }
}
