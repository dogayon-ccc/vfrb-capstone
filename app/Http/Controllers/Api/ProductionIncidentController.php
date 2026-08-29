<?php
// app/Http/Controllers/Api/ProductionIncidentController.php
// VFRB Enterprise — Production Incident Reporting (machine breakdown +
// cutting damage), added Aug 25 2026 by Claude Account 3.
//
// Interview-grounded (VFRB_MaamFe_Interview_Transcript_Apr30.docx) — two
// real shop-floor workflows that had no system equivalent before this:
//   1. Machine breakdown — sewer reports to line leader, mechanic called
//      immediately.
//   2. Cutting damage — mover reports on the spot, line leader confirms
//      it wasn't intentional, piece gets re-cut to catch up.
//
// Flow: reported (any staff) -> acknowledged (staff/manager confirms +
// dispatches fix / confirms not-intentional) -> resolved (staff/manager
// closes it out with resolution notes).
//
// Deliberately NOT manager-exclusive — this is an operational, shop-floor
// tool like QCChecklist/PhysicalCount, not a manager-only page like
// Reports/Suppliers/UserManagement.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\ProductionIncident;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class ProductionIncidentController extends Controller
{
    // GET /api/admin/production-incidents
    public function index(Request $request)
    {
        $query = ProductionIncident::with(['order:order_id,po_reference,status', 'reporter:user_id,name', 'acknowledger:user_id,name'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('incident_type')) {
            $query->where('incident_type', $request->string('incident_type'));
        }
        if ($request->filled('order_id')) {
            $query->where('order_id', $request->integer('order_id'));
        }

        return response()->json($query->paginate(20));
    }

    // POST /api/admin/production-incidents
    public function store(Request $request)
    {
        $data = $request->validate([
            'order_id'     => 'nullable|exists:orders,order_id',
            'incident_type'=> 'required|in:machine_breakdown,cutting_damage',
            'stage'        => 'nullable|in:pattern,segregation,cutting,sewing,qc,pressing,packing',
            'description'  => 'required|string|max:2000',
            'qty_affected' => 'nullable|integer|min:0',
        ]);

        $data['reported_by'] = Auth::id();
        $data['status']      = 'reported';

        $incident = ProductionIncident::create($data);

        // Notify managers immediately — mirrors the interview's "call the
        // mechanic right away" urgency; managers need real-time visibility
        // into shop-floor incidents, not a report they check later.
        $label = $incident->incident_type === 'machine_breakdown' ? 'Machine breakdown' : 'Cutting damage';
        foreach (User::role('manager')->get() as $manager) {
            Notification::create([
                'user_id'    => $manager->user_id,
                'order_id'   => $incident->order_id,
                'message'    => "{$label} reported" . ($incident->order_id ? " on Order #{$incident->order_id}" : '') . ": {$incident->description}",
                'type'       => 'production_incident',
                'title'      => "{$label} Reported",
                'is_read'    => 0,
                'date_sent'  => now(),
            ]);
        }
        Cache::forget('dashboard_stats');

        return response()->json($incident->load(['order:order_id,po_reference', 'reporter:user_id,name']), 201);
    }

    // PATCH /api/admin/production-incidents/{id}/acknowledge
    public function acknowledge(Request $request, int $id)
    {
        $incident = ProductionIncident::findOrFail($id);

        if ($incident->status !== 'reported') {
            return response()->json(['message' => 'Only a newly reported incident can be acknowledged.'], 422);
        }

        $incident->update([
            'status'          => 'acknowledged',
            'acknowledged_by' => Auth::id(),
            'acknowledged_at' => now(),
        ]);

        return response()->json($incident->load(['order:order_id,po_reference', 'reporter:user_id,name', 'acknowledger:user_id,name']));
    }

    // PATCH /api/admin/production-incidents/{id}/resolve
    public function resolve(Request $request, int $id)
    {
        $data = $request->validate([
            'resolution_notes' => 'required|string|max:2000',
        ]);

        $incident = ProductionIncident::findOrFail($id);

        if ($incident->status === 'resolved') {
            return response()->json(['message' => 'Incident is already resolved.'], 422);
        }

        $incident->update([
            'status'            => 'resolved',
            'resolved_at'       => now(),
            'resolution_notes'  => $data['resolution_notes'],
        ]);

        Cache::forget('dashboard_stats');

        return response()->json($incident->load(['order:order_id,po_reference', 'reporter:user_id,name', 'acknowledger:user_id,name']));
    }
}
