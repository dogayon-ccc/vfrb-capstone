<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

// Generic JSON gateway: POST {to, message, sender} with a bearer token. Errors carry the HTTP status only, never the body or token.
class HttpSmsDriver implements SmsDriver
{
    public function send(string $to, string $message): void
    {
        $url = config('services.sms.url');
        if (!$url) {
            throw new RuntimeException('SMS_HTTP_URL is not set.');
        }

        $res = Http::timeout(config('services.sms.timeout', 10))
            ->withToken((string) config('services.sms.token'))
            ->acceptJson()
            ->post($url, ['to' => $to, 'message' => $message, 'sender' => config('services.sms.sender')]);

        if ($res->failed()) {
            throw new RuntimeException("Gateway returned HTTP {$res->status()}.");
        }
    }

    public function name(): string
    {
        return 'http';
    }
}
