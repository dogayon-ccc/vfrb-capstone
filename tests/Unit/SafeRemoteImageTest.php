<?php

namespace Tests\Unit;

use App\Support\SafeRemoteImage;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SafeRemoteImageTest extends TestCase
{
    /** @dataProvider blockedSources */
    public function test_blocks_ssrf_and_unsafe_sources(string $src): void
    {
        Http::fake();
        $this->assertNull(SafeRemoteImage::dataUrl($src));
        Http::assertNothingSent();
    }

    public static function blockedSources(): array
    {
        return [
            'http scheme' => ['http://res.cloudinary.com/x/a.png'],
            'loopback' => ['https://127.0.0.1/a.png'],
            'localhost' => ['https://localhost/a.png'],
            'metadata ip' => ['https://169.254.169.254/latest/meta-data/'],
            'private ip' => ['https://10.0.0.5/a.png'],
            'not allowlisted host' => ['https://example.com/a.png'],
            'userinfo trick' => ['https://res.cloudinary.com@evil.test/a.png'],
            'suffix trick' => ['https://res.cloudinary.com.evil.test/a.png'],
            'custom port' => ['https://res.cloudinary.com:8443/a.png'],
            'file scheme' => ['file:///etc/passwd'],
            'svg data url' => ['data:image/svg+xml;base64,PHN2Zy8+'],
            'non-image data url' => ['data:text/html;base64,PGgxPg=='],
            'malformed data url' => ['data:image/png;base64,@@@'],
        ];
    }

    public function test_accepts_small_inline_png(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgo=';
        $this->assertSame($png, SafeRemoteImage::dataUrl($png));
    }

    public function test_null_and_empty_return_null(): void
    {
        $this->assertNull(SafeRemoteImage::dataUrl(null));
        $this->assertNull(SafeRemoteImage::dataUrl(''));
    }
}
