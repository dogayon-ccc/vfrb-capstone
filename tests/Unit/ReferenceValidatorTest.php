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
        // A later fake() would sit behind the first stub, so start from a fresh factory each time.
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => Http::response($body, $status)]);
    }

    private function check(): array
    {
        return app(ReferenceValidator::class)->validate(self::PNG);
    }

    private function decide(array $d): array
    {
        return app(ReferenceValidator::class)->decide($d + ['colors' => [], 'unknown' => []]);
    }

    public function test_one_top_and_one_bottom_is_accepted_and_image_is_sent_inline(): void
    {
        $this->fake(['is_uniform_reference' => 'yes', 'garments' => ['Scrub Top', 'Scrub Pants'], 'colors' => ['navy'], 'unknown' => ['logo_or_text']]);
        $r = $this->check();
        $this->assertSame(['accepted', 'complete_set', 'Scrub Top', 'Scrub Pants'], [$r['verdict'], $r['reason'], $r['top'], $r['bottom']]);
        Http::assertSent(fn (Request $q) => isset($q['contents'][0]['parts'][0]['inlineData']['data']) && $q['contents'][0]['parts'][0]['inlineData']['mimeType'] === 'image/png');
    }

    public function test_top_only_and_bottom_only_are_rejected_as_incomplete(): void
    {
        $this->assertSame(['rejected', 'incomplete_set_top_only'], array_values(array_intersect_key($this->decide(['is_uniform_reference' => 'yes', 'garments' => ['Blouse']]), ['verdict' => 1, 'reason' => 1])));
        $this->assertSame('incomplete_set_bottom_only', $this->decide(['is_uniform_reference' => 'yes', 'garments' => ['Skirt']])['reason']);
    }

    public function test_hidden_second_piece_is_held_not_rejected(): void
    {
        $r = $this->decide(['is_uniform_reference' => 'yes', 'garments' => ['Polo Shirt'], 'unknown' => ['garment_type']]);
        $this->assertSame(['needs_review', 'second_piece_unclear'], [$r['verdict'], $r['reason']]);
        $this->assertSame('uncertain', $this->decide(['is_uniform_reference' => 'yes', 'garments' => ['Polo Shirt', 'Pants'], 'unknown' => ['garment_type']])['reason']);
    }

    public function test_two_tops_one_piece_and_unsure_are_held(): void
    {
        $this->assertSame('ambiguous_pair', $this->decide(['is_uniform_reference' => 'yes', 'garments' => ['Blouse', 'Blazer', 'Skirt']])['reason']);
        $this->assertSame('one_piece_garment', $this->decide(['is_uniform_reference' => 'yes', 'garments' => ['Dress']])['reason']);
        $this->assertSame('needs_review', $this->decide(['is_uniform_reference' => 'unsure', 'garments' => ['Scrub Top', 'Pants']])['verdict']);
    }

    public function test_unsupported_garment_names_are_dropped_and_held(): void
    {
        $r = $this->decide(['is_uniform_reference' => 'yes', 'garments' => ['Spacesuit']]);
        $this->assertSame(['needs_review', []], [$r['verdict'], $r['garments']]);
    }

    public function test_clearly_unrelated_file_is_rejected(): void
    {
        $this->assertSame('rejected', $this->decide(['is_uniform_reference' => 'no', 'garments' => []])['verdict']);
    }

    public function test_multi_page_or_unreadable_pdf_is_held_without_calling_the_provider(): void
    {
        Http::fake();
        $two = "%PDF-1.4\n/Type /Pages\n/Type /Page\n/Type /Page\n";
        $this->assertSame('pdf_needs_review', app(ReferenceValidator::class)->validate($two)['reason']);
        Http::assertNothingSent();
    }

    public function test_colors_are_filtered_to_plain_words(): void
    {
        $r = app(ReferenceValidator::class)->decide(['is_uniform_reference' => 'yes', 'garments' => ['Polo Shirt', 'Pants'],
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

    public function test_same_file_twice_asks_the_provider_once_and_still_decides_in_code(): void
    {
        $this->fake(['is_uniform_reference' => 'yes', 'garments' => ['Polo Shirt', 'Pants'], 'colors' => ['navy'], 'unknown' => []]);
        $first = $this->check();
        $second = $this->check();
        $this->assertSame(['accepted', 'complete_set'], [$second['verdict'], $second['reason']]);
        $this->assertSame($first['verdict'], $second['verdict']);
        Http::assertSentCount(1);
    }

    public function test_a_failed_provider_answer_is_not_cached(): void
    {
        $this->fake(null, 500);
        $this->check();
        $this->fake(['is_uniform_reference' => 'yes', 'garments' => ['Polo Shirt', 'Pants'], 'colors' => [], 'unknown' => []]);
        $this->assertSame('accepted', $this->check()['verdict']);
    }
}
