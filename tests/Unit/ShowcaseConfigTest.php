<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\DesignController;
use PHPUnit\Framework\TestCase;

// The showcase publishes a design through one allowlist: shape and colours only, never names, overlays, set links or order data.
class ShowcaseConfigTest extends TestCase
{
    public function test_only_garment_shape_and_colours_are_published(): void
    {
        $full = json_encode([
            'name' => 'St. Jude Nursing — Batch 3', 'garment' => 'Polo Shirt', 'sleeve' => 'Short', 'fit' => 'male', 'category' => 'School Uniform',
            'colors' => ['body' => '#274C77'], 'patterns' => ['body' => 'solid'], 'patternParams' => [], 'details' => ['buttons' => ['color' => '#ffffff']],
            'templateId' => 'polo', 'overlays' => [['type' => 'i-text', 'text' => 'JUAN DELA CRUZ']], 'frontOverlays' => [['type' => 'image', 'src' => 'data:image/png;base64,xx']],
            'backOverlays' => [], 'uniformSet' => ['id' => 'abc', 'linkedOrderIds' => [41, 42]], 'previewPng' => 'data:image/png;base64,yy',
            'quantity_ordered' => 120, 'sizes' => ['M' => 60], 'inspirationId' => 'x',
        ]);
        $pub = DesignController::showcaseConfig($full);

        $this->assertSame(['garment', 'sleeve', 'fit', 'category', 'colors', 'patterns', 'patternParams', 'details', 'templateId'], array_keys($pub));
        foreach (['JUAN', 'Jude', 'data:image', 'linkedOrderIds', '120', 'sizes'] as $leak) {
            $this->assertStringNotContainsString($leak, json_encode($pub));
        }
    }

    public function test_missing_or_broken_config_publishes_nothing(): void
    {
        $this->assertNull(DesignController::showcaseConfig(null));
        $this->assertNull(DesignController::showcaseConfig('not json'));
    }
}
