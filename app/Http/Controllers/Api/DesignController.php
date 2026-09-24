<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Design;
use App\Models\DesignShowcaseAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class DesignController extends Controller
{
    // Mirrors OrderController::designRefDisk() — same disk convention,
    // duplicated per-controller rather than shared, matching how
    // SettingsController::logoDisk() and OrderController each keep
    // their own copy in this codebase.
    private function designRefDisk(): string
    {
        if (config('filesystems.disks.cloudinary.cloud')) {
            return 'cloudinary';
        }
        return config('filesystems.default') === 's3' ? 's3' : 'public';
    }

    private function resolvePhotoUrl(?string $rawPath): ?string
    {
        if (!$rawPath) return null;
        return Storage::disk($this->designRefDisk())->url($rawPath);
    }

    // InspoGallery.jsx: GET /api/customer/designs
    // Returns starter catalog templates (source_order_id null) plus this
    // customer's own past designs (auto-archived on order completion —
    // see ProductionStageService::archiveCompletedDesign).
    public function customerIndex(Request $request)
    {
        $userId = Auth::id();

        $designs = Design::where('is_active', 1)
            ->where(function ($q) use ($userId) {
                $q->whereNull('source_order_id')
                  ->orWhereHas('orders', fn ($o) => $o->where('user_id', $userId));
            })
            ->orderByDesc('created_at')
            ->get();

        return response()->json($designs->map(fn (Design $d) => [
            'id'          => $d->design_id,
            'label'       => $d->design_name,
            'category'    => $d->category,
            'garment'     => $d->garment_type,
            'sleeve'      => $d->sleeve_type,
            'photo_path'  => $this->resolvePhotoUrl($d->photo_path),
            'config'      => $d->custom_builder_config ? json_decode($d->custom_builder_config, true) : null,
            'is_archived' => (bool) $d->source_order_id,
            'showcase_status' => $d->showcase_status,
        ]));
    }

    // Scoped to the customer's own archived designs only — never a shared
    // starter template (source_order_id null), never another customer's.
    private function findOwnArchivedDesign(int $id): ?Design
    {
        return Design::whereNotNull('source_order_id')
            ->whereHas('orders', fn ($o) => $o->where('user_id', Auth::id()))
            ->find($id);
    }

    // InspoGallery.jsx: PUT /api/customer/designs/{id} — rename
    public function customerUpdate(Request $request, int $id)
    {
        $validated = $request->validate(['design_name' => 'required|string|max:100']);

        $design = $this->findOwnArchivedDesign($id);
        if (!$design) {
            return response()->json(['message' => 'Design not found.'], 404);
        }

        $design->update(['design_name' => $validated['design_name']]);
        return response()->json(['id' => $design->design_id, 'label' => $design->design_name]);
    }

    // InspoGallery.jsx: DELETE /api/customer/designs/{id}
    // Soft-delete only (is_active = 0) — a hard delete would orphan any
    // order.design_id still pointing at this row.
    public function customerDestroy(int $id)
    {
        $design = $this->findOwnArchivedDesign($id);
        if (!$design) {
            return response()->json(['message' => 'Design not found.'], 404);
        }

        $design->update(['is_active' => 0]);
        return response()->json(['success' => true]);
    }

    // Customer opts an own archived design into the cross-client showcase.
    // Stays private (showcase_status default 'none') until a manager approves.
    public function customerSubmitShowcase(int $id)
    {
        $design = $this->findOwnArchivedDesign($id);
        if (!$design) {
            return response()->json(['message' => 'Design not found.'], 404);
        }
        if (in_array($design->showcase_status, ['submitted', 'approved'])) {
            return response()->json(['message' => 'Already submitted.'], 409);
        }

        $design->update(['showcase_status' => 'submitted', 'submitted_by_user_id' => Auth::id()]);
        DesignShowcaseAudit::create(['design_id' => $id, 'actor_user_id' => Auth::id(), 'action' => 'submitted']);

        return response()->json(['success' => true]);
    }

    // Public across all customers — DTO allowlist only, no user_id/config leak.
    public function customerShowcaseIndex()
    {
        $designs = Design::where('showcase_status', 'approved')->orderByDesc('showcase_approved_at')->get();

        return response()->json($designs->map(fn (Design $d) => [
            'id'         => $d->design_id,
            'label'      => $d->showcase_label,
            'garment'    => $d->garment_type,
            'sleeve'     => $d->sleeve_type,
            'photo_path' => $this->resolvePhotoUrl($d->photo_path),
            'config'     => $d->custom_builder_config ? json_decode($d->custom_builder_config, true) : null,
        ]));
    }

    public function adminShowcaseQueue()
    {
        $designs = Design::where('showcase_status', 'submitted')->orderBy('created_at')->get();

        return response()->json($designs->map(fn (Design $d) => [
            'id'       => $d->design_id,
            'label'    => $d->design_name,
            'garment'  => $d->garment_type,
            'sleeve'   => $d->sleeve_type,
            'config'   => $d->custom_builder_config ? json_decode($d->custom_builder_config, true) : null,
        ]));
    }

    public function adminShowcaseApprove(Request $request, int $id)
    {
        $validated = $request->validate(['showcase_label' => 'required|string|max:80']);

        $design = Design::where('showcase_status', 'submitted')->find($id);
        if (!$design) {
            return response()->json(['message' => 'Design not found or not pending.'], 404);
        }

        $design->update([
            'showcase_status'       => 'approved',
            'showcase_label'        => $validated['showcase_label'],
            'showcase_approved_by'  => Auth::id(),
            'showcase_approved_at'  => now(),
        ]);
        DesignShowcaseAudit::create(['design_id' => $id, 'actor_user_id' => Auth::id(), 'action' => 'approved']);

        return response()->json(['success' => true]);
    }

    public function adminShowcaseReject(Request $request, int $id)
    {
        $validated = $request->validate(['note' => 'nullable|string|max:255']);

        $design = Design::where('showcase_status', 'submitted')->find($id);
        if (!$design) {
            return response()->json(['message' => 'Design not found or not pending.'], 404);
        }

        $design->update(['showcase_status' => 'rejected']);
        DesignShowcaseAudit::create([
            'design_id' => $id, 'actor_user_id' => Auth::id(), 'action' => 'rejected', 'note' => $validated['note'] ?? null,
        ]);

        return response()->json(['success' => true]);
    }

    // Pulls a previously-approved design back out of the showcase.
    public function adminShowcaseRemove(int $id)
    {
        $design = Design::where('showcase_status', 'approved')->find($id);
        if (!$design) {
            return response()->json(['message' => 'Design not found or not approved.'], 404);
        }

        $design->update(['showcase_status' => 'removed']);
        DesignShowcaseAudit::create(['design_id' => $id, 'actor_user_id' => Auth::id(), 'action' => 'removed']);

        return response()->json(['success' => true]);
    }
}
