<?php

namespace App\Support;

// Upload limits the validator enforces and GET /api/customer/upload-rules serves, so the client never keeps its own copy.
final class UploadRules
{
    public const REFERENCE_MIMES = ['jpg', 'jpeg', 'png', 'pdf'];

    public const REFERENCE_MAX_KB = 5120;

    public static function reference(): string
    {
        return 'nullable|file|mimes:'.implode(',', self::REFERENCE_MIMES).'|max:'.self::REFERENCE_MAX_KB;
    }

    public static function forClient(): array
    {
        return ['reference' => ['mimes' => self::REFERENCE_MIMES, 'max_kb' => self::REFERENCE_MAX_KB]];
    }
}
