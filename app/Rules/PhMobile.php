<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// Philippine mobile number: 09XXXXXXXXX or +639XXXXXXXXX. Mirrors src/utils/phPhone.js in the frontend.
class PhMobile implements ValidationRule
{
    // Strips spaces, dashes, dots and brackets so "0917 123-4567" is stored as "09171234567".
    public static function normalize($value): ?string
    {
        $v = preg_replace('/[\s\-().]/', '', (string) ($value ?? ''));
        return $v === '' ? null : $v;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!preg_match('/^(09\d{9}|\+639\d{9})$/', (string) $value)) {
            $fail('Use a PH mobile number: 09XX XXX XXXX or +63 9XX XXX XXXX.');
        }
    }
}
