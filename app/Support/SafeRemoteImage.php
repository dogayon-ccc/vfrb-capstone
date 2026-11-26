<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

// SSRF-safe image fetch for server-side PDFs. https only, exact host allowlist, public IPs only (pinned), no redirects, size and type capped.
final class SafeRemoteImage
{
    public const MAX_BYTES = 5_242_880;

    private const TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    public static function dataUrl(?string $src): ?string
    {
        if (! $src) {
            return null;
        }
        if (str_starts_with($src, 'data:')) {
            return self::inline($src);
        }

        return self::remote($src);
    }

    private static function inline(string $src): ?string
    {
        if (! preg_match('#^data:(image/(?:png|jpeg|webp|gif));base64,([A-Za-z0-9+/=\s]+)$#', $src, $m)) {
            return null;
        }

        return strlen($m[2]) <= (int) (self::MAX_BYTES * 4 / 3) ? $src : null;
    }

    private static function remote(string $src): ?string
    {
        $p = parse_url($src);
        if (! $p || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && $p['port'] !== 443)) {
            return null;
        }
        $host = strtolower($p['host'] ?? '');
        if (! in_array($host, self::allowedHosts(), true)) {
            return null;
        }
        $ip = self::publicIp($host);
        if (! $ip) {
            return null;
        }

        try {
            $res = Http::timeout(8)->connectTimeout(3)->withoutRedirecting()->withOptions([
                'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$ip}"]],
                'on_headers' => function ($r) {
                    if ((int) $r->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                        throw new \RuntimeException('too large');
                    }
                },
            ])->get($src);
            $type = strtolower(trim(explode(';', (string) $res->header('Content-Type'))[0]));
            if (! $res->successful() || ! in_array($type, self::TYPES, true) || strlen($res->body()) > self::MAX_BYTES) {
                return null;
            }

            return "data:{$type};base64,".base64_encode($res->body());
        } catch (\Throwable) {
            return null;
        }
    }

    private static function allowedHosts(): array
    {
        $extra = array_filter(array_map('trim', explode(',', strtolower((string) env('PDF_IMAGE_ALLOWED_HOSTS', '')))));

        return array_values(array_unique(['res.cloudinary.com', ...$extra]));
    }

    // First resolved address, only if every resolved address is public (rejects private, loopback, link-local, reserved, metadata).
    private static function publicIp(string $host): ?string
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
        }
        $ips = array_filter($ips);
        if (! $ips) {
            return null;
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }

        return reset($ips);
    }
}
