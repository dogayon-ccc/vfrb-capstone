<?php
// app/Http/Controllers/Api/ProfilePhotoController.php
// Profile photo for any role, always the signed-in user's own account (no id in the route, so no one else's can be touched).
// Stored in users.avatar on the shared upload disk (App\Support\UploadDisk); a Google avatar URL there is replaced, never deleted.
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\UploadDisk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProfilePhotoController extends Controller
{
    public const MAX_KB = 2048;

    // ── POST /api/profile/photo ──────────────────────────────────────
    public function store(Request $request)
    {
        // Content-sniffed raster images only (no SVG), 2 MB, sane dimensions; stored under a random name, never the client's.
        $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:' . self::MAX_KB,
                        'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000'],
        ], ['photo.dimensions' => 'Use an image between 64 and 4000 pixels on each side.']);

        $user = $request->user();
        $disk = UploadDisk::name();
        $path = $request->file('photo')->store("avatars/{$user->user_id}", $disk);

        $old = DB::table('users')->where('user_id', $user->user_id)->value('avatar');
        DB::table('users')->where('user_id', $user->user_id)->update(['avatar' => $path, 'updated_at' => now()]);
        $this->deleteOwnFile($old, $user->user_id);

        return response()->json(['avatar' => UploadDisk::url($path)], 201);
    }

    // ── DELETE /api/profile/photo ────────────────────────────────────
    public function destroy(Request $request)
    {
        $user = $request->user();
        $old  = DB::table('users')->where('user_id', $user->user_id)->value('avatar');
        DB::table('users')->where('user_id', $user->user_id)->update(['avatar' => null, 'updated_at' => now()]);
        $this->deleteOwnFile($old, $user->user_id);

        return response()->json(['avatar' => null]);
    }

    // Only files this feature stored for this user; external URLs and anything outside avatars/{id}/ are left alone.
    private function deleteOwnFile(?string $path, int $userId): void
    {
        if (!$path || !str_starts_with($path, "avatars/{$userId}/")) {
            return;
        }
        try {
            Storage::disk(UploadDisk::name())->delete($path);
        } catch (\Throwable $e) {
            \Log::warning("Old profile photo not removed for user #{$userId}: " . $e->getMessage());
        }
    }
}
