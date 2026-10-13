<?php

namespace App\Support;

// Where user uploads go: Cloudinary when configured, then S3, else the local 'public' disk (dev only; Railway wipes it).
final class UploadDisk
{
    public static function name(): string
    {
        if (config('filesystems.disks.cloudinary.cloud')) {
            return 'cloudinary';
        }
        return config('filesystems.default') === 's3' ? 's3' : 'public';
    }

    // Public URL for a stored upload; external URLs (e.g. a Google avatar) pass through unchanged.
    public static function url(?string $path): ?string
    {
        return FileUrl::for(self::name(), $path);
    }
}
