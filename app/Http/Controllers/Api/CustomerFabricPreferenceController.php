<?php
// app/Http/Controllers/Api/CustomerFabricPreferenceController.php
// Customer's saved fabric materials. The picker catalog is the existing
// AIController::customerMaterialsCatalog (no stock/cost exposed) — no new
// catalog endpoint here, filter client-side to category === 'Fabric'.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerFabricPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerFabricPreferenceController extends Controller
{
    // GET /api/customer/fabric-preferences
    public function index()
    {
        return response()->json(
            CustomerFabricPreference::where('user_id', Auth::id())
                ->with('material:material_id,material_name,category,unit')
                ->orderByDesc('created_at')->get()
        );
    }

    // POST /api/customer/fabric-preferences  { material_id, notes? }
    public function store(Request $request)
    {
        $data = $request->validate([
            'material_id' => 'required|integer|exists:materials,material_id',
            'notes'       => 'nullable|string|max:255',
        ]);

        $pref = CustomerFabricPreference::updateOrCreate(
            ['user_id' => Auth::id(), 'material_id' => $data['material_id']],
            ['notes' => $data['notes'] ?? null]
        );

        return response()->json($pref->load('material:material_id,material_name,category,unit'), 201);
    }

    // DELETE /api/customer/fabric-preferences/{id}
    public function destroy(int $id)
    {
        CustomerFabricPreference::where('user_id', Auth::id())->where('preference_id', $id)->firstOrFail()->delete();
        return response()->json(['deleted' => true]);
    }
}
