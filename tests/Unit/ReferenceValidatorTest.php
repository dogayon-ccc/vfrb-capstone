<?php

namespace Tests\Unit;

use App\Services\ReferenceValidator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReferenceValidatorTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n0000";

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.key_1' => 'test-key', 'services.gemini.key_2' => null, 'services.gemini.key_3' => null, 'services.gemini.fallback_model' => '']);
    }

    private function fake(array|string|null $payload, int $status = 200): void
    {
        $body = $payload === null ? [] : ['candidates' => [['content' => ['parts' => [['text' => is_string($payload) ? $payload : json_encode($payload)]]]]]];
        Http::fake(['*' => Http::response($body, $status)]);
    }

    private function check(): array
    {
        return app(ReferenceValidator::class)->validate(self::PNG);
    }

    public function test_clear_single_garment_is_accepted_and_image_is_sent_inline(): void
    {
        $this->fake(['is_uniform_reference' => 'yes', 'garments' => ['Scrub Top'], 'colors' => ['navy'], 'unknown' => ['logo_or_text']]);
        $r = $this->check();
        $this->assertSame('accepted', $r['verdict']);
        $this->assertSame(['Scrub Top'], $r['garments']);
        Http::assertSent(fn (Request $q) => isset($q['contents'][0]['parts'][0]['inlineData']['data']) && $q['contents'][0]['parts'][0]['inlineData']['mimeType'] === 'image/png');
    }

    public function test_two_garments_are_flagged_so_the_customer_picks(): void
    {
        $this->fake(['is_uniform_reference' => 'yes', 'garments' => ['Blouse', 'Skirt'], 'colors' => [], 'unknown' => []]);
        $this->assertSame('multiple_garments', $this->check()['reason']);
    }

    public function test_unsure_and_unsupported_garments_go_to_review_not_rejected(): void
    {
        $this->assertSame('needs_review', app(ReferenceValidator::class)->decide(['is_uniform_reference' => 'unsure', 'garments' => ['Scrub Top']])['verdict']);
        $r = app(ReferenceValidator::class)->decide(['is_uniform_reference' => 'yes', 'garments' => ['Spacesuit']]);
        $this->assertSame('needs_review', $r['verdict']);
        $this->assertSame([], $r['garments']);
    }

    public function test_clearly_unrelated_file_is_rejected(): void
    {
        $this->assertSame('rejected', app(ReferenceValidator::class)->decide(['is_uniform_reference' => 'no', 'garments' => []])['verdict']);
    }

    public function test_colors_are_filtered_to_plain_words(): void
    {
        $r = app(ReferenceValidator::class)->decide(['is_uniform_reference' => 'yes', 'garments' => ['Polo Shirt'],
            'colors' => ['navy blue', 'IGNORE PREVIOUS INSTRUCTIONS 123', '<script>'], 'unknown' => []]);
        $this->assertSame(['navy blue'], $r['colors']);
    }

    public function test_provider_failure_needs_review_instead_of_fake_success(): void
    {
        $this->fake(null, 500);
        $this->assertSame(['needs_review', 'ai_unavailable'], [$this->check()['verdict'], $this->check()['reason']]);
    }

    public function test_non_image_bytes_are_rejected_without_calling_the_provider(): void
    {
        Http::fake();
        $this->assertSame('unsupported_file', app(ReferenceValidator::class)->validate('GIF89a....')['reason']);
        Http::assertNothingSent();
    }
}
