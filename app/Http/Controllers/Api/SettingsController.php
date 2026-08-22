<?php
// app/Http/Controllers/Api/SettingsController.php
// System Settings backend (Aug 21 2026 session).
//
// SCOPE (confirmed with Dave):
//   Company settings — manager can view + edit, staff view only.
//     Route split follows the exact pattern already used for
//     Suppliers/Reports/Users: GET lives in the shared
//     'role:staff,manager' group, PATCH/logo upload live in the
//     'role:manager'-only group. No new permission mechanism invented.
//   Notification preferences — every authenticated user manages their
//     OWN row only (no role gate beyond being logged in). NOT a
//     company-wide setting.
//
// LOGO UPLOAD — real file upload (per Dave, Aug 21 2026), not a text
//   field. Disk selection is explicit (see logoDisk() below): 's3' when
//   FILESYSTEM_DISK=s3 is set (Railway/production, once AWS_* creds are
//   filled in), 'public' otherwise (local dev — storage/app/public,
//   symlinked via `php artisan storage:link`). Deliberately NEVER uses
//   Laravel's 'local' disk for this — its Laravel-12 default root
//   (storage/app/private) isn't web-servable and produced broken image
//   URLs in testing (Aug 21 2026) despite the upload itself succeeding.
//   Railway's filesystem is ephemeral regardless of disk, so 'public' is
//   still dev-only — a Railway redeploy wipes anything saved there.
//
// SMS / EMAIL CAVEAT — sms_enabled has no real provider anywhere in
//   this codebase. email_enabled has no real provider either right
//   now — Mailtrap is a sandbox inbox, not production mail delivery.
//   This controller stores the preference honestly; it does not claim
//   either channel is live. The frontend is responsible for labeling
//   both accordingly — see Settings.jsx.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\NotificationPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    // Laravel 12's default 'local' disk root is storage/app/private — NOT
    // web-servable and has no 'url' resolution, so a raw
    // config('filesystems.default') here would silently produce broken
    // image URLs whenever FILESYSTEM_DISK is left at its default (exactly
    // what happened in local dev testing, Aug 21 2026 — upload "succeeded"
    // but the returned URL could never resolve). Route anything that
    // isn't explicitly 's3' to the 'public' disk instead (storage/app/public,
    // symlinked via `php artisan storage:link`) — that's the only pairing
    // that's actually publicly viewable.
    //
    // UPDATE (Aug 22 2026): switched primary target to Cloudinary — Dave's
    // AWS/R2 sign-up requires a payment method on file, which isn't an
    // option (student, no card/bank/PayPal access). Cloudinary's free plan
    // requires no card at all and is genuinely free forever, not a trial.
    // cloudinary-labs/cloudinary-laravel implements Laravel's Storage
    // Driver interface, so Storage::disk('cloudinary') works exactly like
    // Storage::disk('s3') did — no other method in this controller needed
    // to change. S3 stays as a secondary fallback path in case that setup
    // is ever finished later; 'public' is the last-resort local-dev-only
    // option, same as before.
    private function logoDisk(): string
    {
        if (config('filesystems.disks.cloudinary.cloud')) {
            return 'cloudinary';
        }
        return config('filesystems.default') === 's3' ? 's3' : 'public';
    }

    // ── GET /api/admin/settings/company ─────────────────────────────
    // Visible to staff + manager (view-only for staff, enforced by
    // route group, not here — this method itself has no side effects).
    public function companyShow(Request $request)
    {
        $settings = CompanySetting::find(1);

        if (!$settings) {
            // Ships empty like material_usage_rates — we do not invent
            // VFRB's real branding details. First manager save creates row 1.
            return response()->json([
                'company_name'   => 'VFRB Enterprise',
                'logo_url'       => null,
                'address'        => null,
                'contact_number' => null,
                'contact_email'  => null,
                'updated_at'     => null,
            ]);
        }

        return response()->json([
            'company_name'   => $settings->company_name,
            'logo_url'       => $settings->logo_path ? Storage::disk($this->logoDisk())->url($settings->logo_path) : null,
            'address'        => $settings->address,
            'contact_number' => $settings->contact_number,
            'contact_email'  => $settings->contact_email,
            'updated_at'     => $settings->updated_at,
        ]);
    }

    // ── PATCH /api/admin/settings/company ───────────────────────────
    // Manager-only (route group). Text fields only — logo goes through
    // companyLogoUpload() below as a separate multipart request.
    public function companyUpdate(Request $request)
    {
        $request->validate([
            'company_name'   => 'sometimes|string|max:150',
            'address'        => 'nullable|string|max:255',
            'contact_number' => 'nullable|string|max:20',
            'contact_email'  => 'nullable|email|max:100',
        ]);

        $settings = CompanySetting::find(1) ?? new CompanySetting(['id' => 1]);
        $settings->id = 1; // enforced single row — see migration note
        $settings->fill($request->only(['company_name', 'address', 'contact_number', 'contact_email']));
        $settings->updated_by = $request->user()->user_id;
        $settings->save();

        return response()->json(['message' => 'Company settings updated.']);
    }

    // ── POST /api/admin/settings/company/logo ───────────────────────
    // Manager-only. Separate endpoint from companyUpdate() so the
    // multipart upload doesn't force every text-only save through
    // multipart/form-data.
    public function companyLogoUpload(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048', // 2MB
        ]);

        $settings = CompanySetting::find(1) ?? new CompanySetting(['id' => 1]);
        $settings->id = 1;

        $disk = $this->logoDisk();

        // Remove old logo file so orphans don't accumulate on every re-upload.
        if ($settings->logo_path && Storage::disk($disk)->exists($settings->logo_path)) {
            Storage::disk($disk)->delete($settings->logo_path);
        }

        $path = $request->file('logo')->store('company-logo', $disk);

        $settings->logo_path = $path;
        $settings->updated_by = $request->user()->user_id;
        $settings->save();

        return response()->json([
            'message'  => 'Logo uploaded.',
            'logo_url' => Storage::disk($disk)->url($path),
        ]);
    }

    // ── GET /api/settings/notifications ──────────────────────────────
    // Any authenticated user — their own row only. No role gate.
    public function notificationShow(Request $request)
    {
        $pref = NotificationPreference::find($request->user()->user_id);

        return response()->json([
            'email_enabled' => $pref?->email_enabled ?? true,
            'sms_enabled'   => $pref?->sms_enabled ?? false,
            // Frontend caveat flags — sent from backend so the "not real yet"
            // warning can't drift out of sync between FE and BE independently.
            'email_provider_live' => false, // Mailtrap sandbox only as of this build
            'sms_provider_live'   => false, // no SMS gateway configured anywhere
        ]);
    }

    // ── PATCH /api/settings/notifications ────────────────────────────
    public function notificationUpdate(Request $request)
    {
        $request->validate([
            'email_enabled' => 'sometimes|boolean',
            'sms_enabled'   => 'sometimes|boolean',
        ]);

        $pref = NotificationPreference::firstOrNew(['user_id' => $request->user()->user_id]);
        $pref->fill($request->only(['email_enabled', 'sms_enabled']));
        $pref->save();

        return response()->json(['message' => 'Notification preferences updated.']);
    }
}
