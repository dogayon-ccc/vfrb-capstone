<?php

// app/Http/Controllers/Api/CustomerContactController.php
// Walk-in customer contacts: Administrators record them, the owner can only read.
// Never creates a users row or a role; linking to an account happens on email proof.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CustomerContact;
use App\Support\Sql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CustomerContactController extends Controller
{
    // GET /api/admin/customer-contacts?search=&linked=1|0&per_page=
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
        $search = trim((string) $request->input('search', ''));

        $query = CustomerContact::query()->select(
            'contact_id', 'name', 'email', 'mobile', 'address', 'organization_name',
            'user_id', 'linked_at', 'created_by', 'created_at', 'updated_at'
        );

        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', Sql::like(), $like)
                    ->orWhere('email', Sql::like(), $like)
                    ->orWhere('mobile', Sql::like(), $like)
                    ->orWhere('organization_name', Sql::like(), $like);
            });
        }
        if ($request->filled('linked')) {
            $request->boolean('linked') ? $query->whereNotNull('user_id') : $query->whereNull('user_id');
        }

        return response()->json($query->orderBy('name')->paginate($perPage));
    }

    // POST /api/admin/customer-contacts
    public function store(Request $request): JsonResponse
    {
        $request->merge(['email' => $request->filled('email') ? strtolower(trim((string) $request->input('email'))) : null]);
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'nullable|required_without:mobile|email|max:100|unique:customer_contacts,email',
            'mobile' => ['nullable', 'required_without:email', 'string', 'max:20', 'regex:/^\+?[0-9 \-]{7,20}$/'],
            'address' => 'nullable|string|max:255',
            'organization_name' => 'nullable|string|max:100',
        ], [
            'email.unique' => 'A contact with this email already exists.',
            'email.required_without' => 'Enter an email or a mobile number.',
            'mobile.required_without' => 'Enter an email or a mobile number.',
        ]);

        $contact = DB::transaction(function () use ($data) {
            $contact = CustomerContact::create([...$data, 'created_by' => Auth::id()]);
            if ($contact->email && ($userId = CustomerContact::verifiedCustomerIdFor($contact->email))) {
                CustomerContact::linkVerifiedUser($userId, $contact->email);
            }

            AuditLog::record('customer_contact_created', 'customer_contacts', $contact->contact_id);

            return $contact->fresh();
        });

        return response()->json($contact, 201);
    }
}
