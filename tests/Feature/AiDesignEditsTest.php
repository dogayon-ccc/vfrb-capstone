<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// POST /api/ai/design-edits with Gemini faked. Writes nothing to any database.
class AiDesignEditsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.key_1' => 'test-key', 'services.gemini.key_2' => null, 'services.gemini.key_3' => null]);
    }

    private function gemini(mixed $payload)
    {
        return Http::response(['candidates' => [['content' => ['parts' => [['text' => is_string($payload) ? $payload : json_encode($payload)]]]]]]);
    }

    private function body(array $over = []): array
    {
        return array_replace_recursive([
            'instruction' => 'Make the collar white and add SMITH on the back',
            'template'    => ['id' => 'polo-short-male', 'garment' => 'Polo Shirt', 'zones' => ['body', 'collar', 'sleeve'],
                              'placements' => ['left_chest', 'back_center', 'left_arm'], 'patterns' => ['solid', 'hstripes']],
            'state'       => ['colors' => ['body' => '#1B2A4A', 'collar' => '#1B2A4A'], 'patterns' => ['body' => 'solid'], 'texts' => []],
        ], $over);
    }

    public function test_valid_edits_come_back_and_invented_ones_are_rejected(): void
    {
        Http::fake(['*' => $this->gemini(['summary' => 'White collar and SMITH on the back.', 'operations' => [
            ['op' => 'set_color', 'zone' => 'collar', 'hex' => '#ffffff'],
            ['op' => 'add_text', 'text' => 'SMITH', 'placement' => 'back_center'],
            ['op' => 'set_color', 'zone' => 'hood', 'hex' => '#000000'],
            ['op' => 'add_sleeve', 'zone' => 'sleeve'],
        ]])]);

        $res = $this->postJson('/api/ai/design-edits', $this->body())->assertOk();
        $res->assertJsonPath('operations', [
            ['op' => 'set_color', 'zone' => 'collar', 'hex' => '#FFFFFF'],
            ['op' => 'add_text', 'text' => 'SMITH', 'placement' => 'back_center'],
        ]);
        $this->assertCount(2, $res->json('rejected'));
        $res->assertJsonPath('template_id', 'polo-short-male');

        // The model was constrained to this template's own values.
        Http::assertSent(function (Request $r) {
            $schema = $r['generationConfig']['responseJsonSchema'] ?? $r['generationConfig']['responseSchema'] ?? null;
            return $schema && $schema['properties']['operations']['items']['properties']['zone']['enum'] === ['body', 'collar', 'sleeve'];
        });
    }

    public function test_unknown_template_zones_are_refused_before_calling_gemini(): void
    {
        Http::fake();
        $this->postJson('/api/ai/design-edits', $this->body(['template' => ['zones' => ['body', 'cape']]]))->assertStatus(422);
        $this->postJson('/api/ai/design-edits', $this->body(['template' => ['placements' => ['left_hip']]]))->assertStatus(422);
        $this->postJson('/api/ai/design-edits', ['instruction' => 'x'])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_price_or_quantity_talk_never_reaches_the_customer(): void
    {
        Http::fake(['*' => $this->gemini(['summary' => 'This costs ₱500 for 20 pcs.', 'operations' => [['op' => 'set_color', 'zone' => 'body', 'hex' => '#000000']]])]);
        $this->postJson('/api/ai/design-edits', $this->body())->assertOk()->assertJsonPath('summary', 'Edits ready to apply.');
    }

    public function test_unusable_or_missing_ai_reply_is_an_error_not_a_guess(): void
    {
        Http::fake(['*' => $this->gemini('not json at all')]);
        $this->postJson('/api/ai/design-edits', $this->body())->assertStatus(502);

        config(['services.gemini.key_1' => null]);
        $this->postJson('/api/ai/design-edits', $this->body())->assertStatus(503);
    }
}
