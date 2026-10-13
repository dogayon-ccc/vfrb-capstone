<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\FileUrl;
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
        return FileUrl::for($this->designRefDisk(), $rawPath);
    }

    // What the showcase may publish of a design: garment shape, colours, patterns and details only. Never the design name,
    // overlays (the client's printed names and logos), the uniform set (its order links) or the order's rendered preview photo.
    public const SHOWCASE_KEYS = ['garment', 'fit', 'sleeve', 'category', 'colors', 'patterns', 'patternParams', 'details', 'templateId'];
    public static function showcaseConfig(?string $json): ?array
    {
        $c = $json ? json_decode($json, true) : null;
        return is_array($c) ? array_intersect_key($c, array_flip(self::SHOWCASE_KEYS)) : null;
    }

    // One public shape for the showcase and for the manager's review, so what is approved is exactly what is published.
    private function showcaseDto(Design $d, string $label): array
    {
        return ['id' => $d->design_id, 'label' => $label, 'garment' => $d->garment_type, 'sleeve' => $d->sleeve_type, 'photo_path' => null,
                'config' => self::showcaseConfig($d->custom_builder_config)];
    }

    // Latest moderation note per design (the reason a manager gave when rejecting), for the owner only.
    private function showcaseNotes(array $ids): array
    {
        return DesignShowcaseAudit::whereIn('design_id', $ids)->where('action', 'rejected')->orderBy('log_id')->pluck('note', 'design_id')->all();
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

        $notes = $this->showcaseNotes($designs->where('showcase_status', 'rejected')->pluck('design_id')->all());

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
            'showcase_note'   => $d->showcase_status === 'rejected' ? ($notes[$d->design_id] ?? null) : null,
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

        // Deleting a design also withdraws it from the showcase: consent ends with the design.
        if (in_array($design->showcase_status, ['submitted', 'approved'])) {
            DesignShowcaseAudit::create(['design_id' => $id, 'actor_user_id' => Auth::id(), 'action' => 'removed', 'note' => 'Deleted by the customer']);
        }
        $design->update(['is_active' => 0, 'showcase_status' => in_array($design->showcase_status, ['submitted', 'approved']) ? 'none' : $design->showcase_status]);
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

    // Customer withdraws consent: a submitted or approved design goes back to private at once.
    public function customerWithdrawShowcase(int $id)
    {
        $design = $this->findOwnArchivedDesign($id);
        if (!$design) {
            return response()->json(['message' => 'Design not found.'], 404);
        }
        if (!in_array($design->showcase_status, ['submitted', 'approved'])) {
            return response()->json(['message' => 'This design is not in the showcase.'], 409);
        }

        $design->update(['showcase_status' => 'none']);
        DesignShowcaseAudit::create(['design_id' => $id, 'actor_user_id' => Auth::id(), 'action' => 'removed', 'note' => 'Withdrawn by the customer']);

        return response()->json(['success' => true, 'showcase_status' => 'none']);
    }

    // Visible to every customer: approved, still-active designs, published through the allowlist only (showcaseDto).
    public function customerShowcaseIndex()
    {
        $designs = Design::where('showcase_status', 'approved')->where('is_active', 1)->orderByDesc('showcase_approved_at')->get();

        return response()->json($designs->map(fn (Design $d) => $this->showcaseDto($d, (string) $d->showcase_label))->values());
    }

    // Manager review: the same public shape the showcase will publish, plus the internal design name for reference.
    // ?status=approved lists what is published now, so a manager can take a design down (showcase-remove).
    public function adminShowcaseQueue(Request $request)
    {
        $status = $request->query('status') === 'approved' ? 'approved' : 'submitted';
        $designs = Design::where('showcase_status', $status)->where('is_active', 1)
            ->orderBy($status === 'approved' ? 'showcase_approved_at' : 'created_at')->get();

        return response()->json($designs->map(fn (Design $d) => $this->showcaseDto($d, (string) ($status === 'approved' ? $d->showcase_label : $d->design_name)))->values());
    }

    public function adminShowcaseApprove(Request $request, int $id)
    {
        $validated = $request->validate(['showcase_label' => 'required|string|max:80']);

        $design = Design::where('showcase_status', 'submitted')->where('is_active', 1)->find($id);
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
