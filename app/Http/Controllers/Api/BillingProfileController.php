<?php
// app/Http/Controllers/Api/BillingProfileController.php
// Saved invoice-recipient details (not a payment method — see migration
// comment). Scoped to Auth::id() on every query.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BillingProfileController extends Controller
{
    // GET /api/customer/billing-profiles
    public function index()
    {
        return response()->json(
            BillingProfile::where('user_id', Auth::id())
                ->orderByDesc('is_default')->orderByDesc('created_at')->get()
        );
    }

    // POST /api/customer/billing-profiles
    public function store(Request $request)
    {
        $data = $this->validated($request);

        $profile = DB::transaction(function () use ($data) {
            if ($data['is_default'] ?? false) {
                BillingProfile::where('user_id', Auth::id())->update(['is_default' => false]);
            }
            return BillingProfile::create([...$data, 'user_id' => Auth::id()]);
        });

        return response()->json($profile, 201);
    }

    // PUT /api/customer/billing-profiles/{id}
    public function update(Request $request, int $id)
    {
        $profile = BillingProfile::where('user_id', Auth::id())->findOrFail($id);
        $data = $this->validated($request);

        DB::transaction(function () use ($profile, $data) {
            if ($data['is_default'] ?? false) {
                BillingProfile::where('user_id', Auth::id())->where('billing_id', '!=', $profile->billing_id)
                    ->update(['is_default' => false]);
            }
            $profile->update($data);
        });

        return response()->json($profile->fresh());
    }

    // DELETE /api/customer/billing-profiles/{id}
    public function destroy(int $id)
    {
        BillingProfile::where('user_id', Auth::id())->findOrFail($id)->delete();
        return response()->json(['deleted' => true]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'billing_name'    => 'required|string|max:150',
            'tin'             => 'nullable|string|max:20',
            'billing_address' => 'required|string|max:255',
            'billing_email'   => 'nullable|email|max:100',
            'is_default'      => 'nullable|boolean',
        ]);
    }
}
