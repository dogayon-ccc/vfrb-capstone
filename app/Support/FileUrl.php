<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

// Public URL for a stored file. The Cloudinary adapter's url() makes an Admin API call per file
// (~1s each), so Cloudinary delivery URLs are built directly from the stored path instead.
final class FileUrl
{
    private const CLOUDINARY_IMAGE = '/\.(png|jpe?g|gif|webp|avif|svg|pdf)$/i';

    public static function for(string $disk, ?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        if (preg_match('#^https?://#', $path)) {
            return $path;
        }

        $cloud = config('filesystems.disks.cloudinary.cloud');
        if ($disk === 'cloudinary' && $cloud && preg_match(self::CLOUDINARY_IMAGE, $path)) {
            return "https://res.cloudinary.com/{$cloud}/image/upload/" . ltrim($path, '/');
        }

        return Storage::disk($disk)->url($path);
    }
}
