<?php
// Client-supplied materials for one order (rules in App\Services\ClientMaterialService).
//   GET  /api/admin/orders/{id}/client-materials  lines (required, received, issued, wastage, returned, on hand, short) + entries
//   POST /api/admin/orders/{id}/client-materials  { type: received|wastage|returned, material_id, qty, note }
// "issued" is never posted: Cutting records it automatically.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\ClientMaterialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ClientMaterialController extends Controller
{
    public function show(int $id, ClientMaterialService $svc)
    {
        if (! DB::table('orders')->where('order_id', $id)->exists()) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $lines = $svc->summary($id);

        return response()->json([
            'ready'        => ClientMaterialService::ready(),
            'lines'        => $lines,
            'entries'      => $svc->entries($id),
            'short'        => array_values(array_filter($lines, fn ($l) => $l['short'] > 0)),
            'closure_note' => $svc->closureBlocker($id),
        ]);
    }

    public function store(Request $request, int $id, ClientMaterialService $svc)
    {
        $data = $request->validate([
            'type'        => 'required|in:received,wastage,returned',
            'material_id' => 'required|integer|exists:materials,material_id',
            'qty'         => 'required|numeric|min:0.01',
            'note'        => 'required_unless:type,received|nullable|string|max:255',
        ], [
            'note.required_unless' => 'Say what happened; the client sees wastage and returns on their slip.',
        ]);

        $error = $svc->record($id, (int) $data['material_id'], $data['type'], (float) $data['qty'], $data['note'] ?? null, (int) Auth::id());
        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        AuditLog::record("client_material_{$data['type']}", 'orders', $id);

        return $this->show($id, $svc)->setStatusCode(201);
    }
}
