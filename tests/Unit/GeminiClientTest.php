<?php

namespace Tests\Unit;

use App\Services\GeminiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiClientTest extends TestCase
{
    private function keys(?string $k1 = 'key-one', ?string $k2 = null, ?string $k3 = null): void
    {
        config(['services.gemini.key_1' => $k1, 'services.gemini.key_2' => $k2, 'services.gemini.key_3' => $k3, 'services.gemini.model' => 'gemini-3.6-flash']);
    }

    private function ok(array $parts): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['candidates' => [['content' => ['parts' => $parts]]]]);
    }

    public function test_call_without_schema_sends_no_schema_fields(): void
    {
        $this->keys();
        Http::fake(['*' => $this->ok([['text' => 'hello']])]);

        $this->assertSame('hello', (new GeminiClient())->call('hi', 100));

        Http::assertSent(function (Request $r) {
            $cfg = $r->data()['generationConfig'];
            return !isset($cfg['responseJsonSchema']) && !isset($cfg['responseMimeType']) && $cfg['maxOutputTokens'] === 100;
        });
    }

    public function test_call_with_schema_sends_json_mode_and_schema(): void
    {
        $this->keys();
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]];
        Http::fake(['*' => $this->ok([['text' => '{"a":"b"}']])]);

        $this->assertSame('{"a":"b"}', (new GeminiClient())->call('hi', 100, $schema));

        Http::assertSent(function (Request $r) use ($schema) {
            $cfg = $r->data()['generationConfig'];
            return $cfg['responseMimeType'] === 'application/json' && $cfg['responseJsonSchema'] === $schema
                && $cfg['thinkingConfig'] === ['thinkingLevel' => 'minimal'];
        });
    }

    public function test_key_travels_in_header_never_in_url(): void
    {
        $this->keys('secret-key-123');
        Http::fake(['*' => $this->ok([['text' => 'x']])]);

        (new GeminiClient())->call('hi');

        Http::assertSent(fn (Request $r) => $r->hasHeader('x-goog-api-key', 'secret-key-123')
            && !str_contains($r->url(), 'secret-key-123')
            && str_contains($r->url(), 'gemini-3.6-flash:generateContent'));
    }

    public function test_thought_parts_are_dropped_and_text_parts_joined(): void
    {
        $this->keys();
        Http::fake(['*' => $this->ok([['text' => 'internal reasoning', 'thought' => true], ['text' => '{"a":'], ['text' => '1}']])]);

        $this->assertSame('{"a":1}', (new GeminiClient())->call('hi'));
    }

    public function test_rate_limited_or_rejected_key_rotates_to_the_next_key(): void
    {
        $this->keys('k1', 'k2', 'k3');
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['message' => 'quota']], 429)
            ->push(['error' => ['message' => 'bad key']], 403)
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'third']]]]]])]);

        $this->assertSame('third', (new GeminiClient())->call('hi'));
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => $r->hasHeader('x-goog-api-key', 'k3'));
    }

    public function test_request_shape_error_stops_without_trying_other_keys(): void
    {
        $this->keys('k1', 'k2');
        Http::fake(['*' => Http::response(['error' => ['message' => 'bad schema']], 400)]);

        $this->assertNull((new GeminiClient())->call('hi'));
        Http::assertSentCount(1);
    }

    public function test_upstream_5xx_returns_null(): void
    {
        $this->keys();
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->assertNull((new GeminiClient())->call('hi'));
    }

    public function test_network_exception_rotates_then_gives_up_with_null(): void
    {
        $this->keys('k1', 'k2');
        $attempts = 0;
        Http::fake(['*' => function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('timeout');
        }]);

        $this->assertNull((new GeminiClient())->call('hi'));
        $this->assertSame(2, $attempts);
    }

    public function test_empty_or_blocked_response_returns_null(): void
    {
        $this->keys();
        Http::fake(['*' => Http::response(['candidates' => [['finishReason' => 'SAFETY']]])]);

        $this->assertNull((new GeminiClient())->call('hi'));
    }

    public function test_no_configured_keys_returns_null_without_any_request(): void
    {
        $this->keys(null, '', null);
        Http::fake();

        $this->assertNull((new GeminiClient())->call('hi'));
        Http::assertNothingSent();
    }
}
