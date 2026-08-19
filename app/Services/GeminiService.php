<?php
// app/Services/GeminiService.php
// FF-2: Upgraded from single-key to 3-key round-robin rotation
//
// .env configuration required:
//   GEMINI_KEY_1=AIzaSy...  (get free at aistudio.google.com/apikey)
//   GEMINI_KEY_2=AIzaSy...
//   GEMINI_KEY_3=AIzaSy...
//   GEMINI_KEY_COUNT=3
//
// If you only have 1 key, set GEMINI_KEY_COUNT=1 and only GEMINI_KEY_1.
// The service still works — rotation just has nothing to rotate to.
//
// DSA: Round-robin via modulo — O(1) per request
//   key_index = Cache::increment('gemini_key_idx') % key_count
//   On 429: Cache::put('gemini_blocked_N', true, 60) — skip for 60s
//
// Free tier per key: 15 RPM | 1M tokens/day | 1500 requests/day
// With 3 keys: effectively 45 RPM before any key hits rate limit

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private string $model;
    private string $baseUrlTemplate;
    private int    $keyCount;

    public function __construct()
    {
        $this->model           = config('services.gemini.model', 'gemini-1.5-flash');
        $this->baseUrlTemplate = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";
        $this->keyCount        = (int) (env('GEMINI_KEY_COUNT', 1));
    }

    // ── Key rotation — DSA: O(1) modulo round-robin ───────────────────────────
    private function getKey(): ?string
    {
        if ($this->keyCount <= 0) return null;

        // Try each key in round-robin order; skip any blocked by 429
        $startIdx = (int) Cache::get('gemini_key_idx', 0);

        for ($attempt = 0; $attempt < $this->keyCount; $attempt++) {
            $idx = ($startIdx + $attempt) % $this->keyCount;
            $keyNum = $idx + 1;

            // Skip keys blocked due to rate limit (60s block window)
            if (Cache::has("gemini_blocked_{$keyNum}")) {
                continue;
            }

            // Advance the round-robin pointer for next call
            Cache::put('gemini_key_idx', ($idx + 1) % $this->keyCount, 3600);

            $key = env("GEMINI_KEY_{$keyNum}");
            if (!$key) {
                // Fall back to the legacy single-key config
                $key = config('services.gemini.api_key');
            }

            if ($key) return [$key, $keyNum];
        }

        // All keys blocked
        Log::warning('GeminiService: all keys rate-limited or unavailable');
        return null;
    }

    // ── Core generate method ──────────────────────────────────────────────────
    public function generate(string $prompt, array $options = []): ?string
    {
        $maxTokens   = $options['maxTokens']   ?? 1500;
        $temperature = $options['temperature'] ?? 0.3;

        $keyResult = $this->getKey();
        if (!$keyResult) {
            return $this->fallbackResponse();
        }
        [$apiKey, $keyNum] = $keyResult;

        try {
            $response = Http::timeout(30)->post(
                "{$this->baseUrlTemplate}?key={$apiKey}",
                [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]]
                    ],
                    'generationConfig' => [
                        'temperature'     => $temperature,
                        'maxOutputTokens' => $maxTokens,
                        'topP'            => 0.8,
                    ],
                    'safetySettings' => [
                        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
                    ],
                ]
            );

            // 429 Rate limit — block this key for 60 seconds, try next
            if ($response->status() === 429) {
                Cache::put("gemini_blocked_{$keyNum}", true, 60);
                Log::warning("GeminiService: key #{$keyNum} rate-limited — blocked 60s");

                // Recursive retry with remaining keys (only once)
                if (!isset($options['_retry'])) {
                    return $this->generate($prompt, array_merge($options, ['_retry' => true]));
                }
                return $this->fallbackResponse();
            }

            if (!$response->successful()) {
                Log::error('GeminiService: API error', [
                    'status'  => $response->status(),
                    'key_num' => $keyNum,
                    'body'    => substr($response->body(), 0, 300),
                ]);
                return null;
            }

            return $response->json('candidates.0.content.parts.0.text');

        } catch (\Exception $e) {
            Log::error('GeminiService: exception', [
                'message' => $e->getMessage(),
                'key_num' => $keyNum,
            ]);
            return null;
        }
    }

    // ── JSON response helper ──────────────────────────────────────────────────
    // Used by: AIController@describeDesign, AIController@recommendMaterials
    public function generateJson(string $prompt, array $options = []): ?array
    {
        $text = $this->generate($prompt, array_merge($options, ['temperature' => 0.1]));
        if (!$text) return null;

        // Strip markdown fences Gemini sometimes wraps JSON in
        $clean = preg_replace('/```json|```/i', '', $text);
        $decoded = json_decode(trim($clean), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('GeminiService: JSON parse failed', ['raw' => substr($text, 0, 300)]);
            return null;
        }

        return $decoded;
    }

    // ── Fallback when all keys are blocked ────────────────────────────────────
    // Returns a plain-language message instead of crashing.
    // The UI should detect this and show a "try again later" state.
    private function fallbackResponse(): string
    {
        return 'AI recommendation is temporarily unavailable due to rate limits. '
             . 'Please try again in 60 seconds. Your order information has been saved.';
    }

    // ── Health check — for admin dashboard status indicator ──────────────────
    public function availableKeyCount(): int
    {
        $available = 0;
        for ($i = 1; $i <= $this->keyCount; $i++) {
            if (!Cache::has("gemini_blocked_{$i}")) {
                $available++;
            }
        }
        return $available;
    }
}
