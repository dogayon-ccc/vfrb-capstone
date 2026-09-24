<?php
// app/Http/Controllers/Api/ShippingAddressController.php
// Customer's own saved address book. Scoped to Auth::id() on every query —
// a customer can never see or touch another customer's addresses.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShippingAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ShippingAddressController extends Controller
{
    // GET /api/customer/shipping-addresses
    public function index()
    {
        return response()->json(
            ShippingAddress::where('user_id', Auth::id())
                ->orderByDesc('is_default')->orderByDesc('created_at')->get()
        );
    }

    // POST /api/customer/shipping-addresses
    public function store(Request $request)
    {
        $data = $this->validated($request);

        $addr = DB::transaction(function () use ($data) {
            if ($data['is_default'] ?? false) {
                ShippingAddress::where('user_id', Auth::id())->update(['is_default' => false]);
            }
            return ShippingAddress::create([...$data, 'user_id' => Auth::id()]);
        });

        return response()->json($addr, 201);
    }

    // PUT /api/customer/shipping-addresses/{id}
    public function update(Request $request, int $id)
    {
        $addr = ShippingAddress::where('user_id', Auth::id())->findOrFail($id);
        $data = $this->validated($request);

        DB::transaction(function () use ($addr, $data) {
            if ($data['is_default'] ?? false) {
                ShippingAddress::where('user_id', Auth::id())->where('shipping_id', '!=', $addr->shipping_id)
                    ->update(['is_default' => false]);
            }
            $addr->update($data);
        });

        return response()->json($addr->fresh());
    }

    // DELETE /api/customer/shipping-addresses/{id}
    public function destroy(int $id)
    {
        ShippingAddress::where('user_id', Auth::id())->findOrFail($id)->delete();
        return response()->json(['deleted' => true]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'label'          => 'required|string|max:50',
            'recipient_name' => 'required|string|max:100',
            'contact_number' => 'required|string|max:20',
            'address_line'   => 'required|string|max:255',
            'city'           => 'required|string|max:100',
            'province'       => 'required|string|max:100',
            'postal_code'    => 'nullable|string|max:10',
            'is_default'     => 'nullable|boolean',
        ]);
    }
}
