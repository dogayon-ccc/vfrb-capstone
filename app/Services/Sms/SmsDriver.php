<?php

namespace App\Services\Sms;

// One SMS gateway. $to is already a normalized +639XXXXXXXXX number. Throws on failure; never logs the token.
interface SmsDriver
{
    public function send(string $to, string $message): void;

    public function name(): string;
}
