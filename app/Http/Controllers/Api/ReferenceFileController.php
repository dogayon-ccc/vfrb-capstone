<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\UploadDisk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Reference uploads are private: only the owning customer or manager/owner staff can read the bytes, never a public URL.
class ReferenceFileController extends Controller
{
    private const TYPES = ['image/jpeg', 'image/png', 'application/pdf'];

    public function mine(Request $request, int $id)
    {
        return $this->send(DB::table('reference_validations')->where('id', $id)->where('user_id', $request->user()->getKey())->first());
    }

    public function staff(int $id)
    {
        return $this->send(DB::table('reference_validations')->where('id', $id)->first());
    }

    private function send(?object $row)
    {
        $disk = Storage::disk(UploadDisk::name());
        if (! $row || ! $disk->exists($row->path)) {
            abort(404);
        }
        $bytes = $disk->get($row->path);
        // The type comes from the bytes, not the stored extension, and anything unexpected is refused.
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! in_array($type, self::TYPES, true)) {
            abort(404);
        }

        return response($bytes, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'inline; filename="reference"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
