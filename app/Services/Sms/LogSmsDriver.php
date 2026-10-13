<?php

namespace App\Services\Sms;

// Default driver: nothing leaves the server; SmsNotify's send log is the only trace.
class LogSmsDriver implements SmsDriver
{
    public function send(string $to, string $message): void {}

    public function name(): string
    {
        return 'log';
    }
}
