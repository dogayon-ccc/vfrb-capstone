<?php

// app/Http/Controllers/Api/CustomerContactController.php
// Walk-in customer contacts: Administrators record them, the owner can only read.
// Never creates a users row or a role; linking to an account happens on email proof.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CustomerContact;
use App\Support\Sql;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CustomerContactController extends Controller
{
    // GET /api/admin/customer-contacts?search=&linked=1|0&requested=1&per_page=
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
        if ($request->boolean('requested')) {
            $query->whereNull('created_by')->whereNull('user_id');
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

    // POST /api/request-access (public): same reply for new, duplicate and honeypot submissions.
    public function requestAccess(Request $request): JsonResponse
    {
        $reply = response()->json(['message' => 'Thank you. An Administrator will contact you about your account.'], 202);
        if ($request->filled('website')) {
            return $reply;
        }

        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'organization' => 'nullable|string|max:100',
            'email' => 'required|email|max:100',
            'mobile' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 \-]{7,20}$/'],
        ]);

        if (CustomerContact::where('email', $data['email'])->exists()) {
            return $reply;
        }

        try {
            DB::transaction(function () use ($data) {
                $contact = CustomerContact::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'mobile' => $data['mobile'] ?? null,
                    'organization_name' => $data['organization'] ?? null,
                ]);

                $now = now();
                $label = $contact->organization_name ? "{$contact->name} ({$contact->organization_name})" : $contact->name;
                $managerIds = DB::table('model_has_roles')
                    ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                    ->where('roles.name', 'manager')
                    ->pluck('model_has_roles.model_id');
                foreach ($managerIds as $uid) {
                    DB::table('notifications')->insert([
                        'user_id' => $uid,
                        'order_id' => null,
                        'title' => 'Access request',
                        'message' => "{$label} requested a customer account. Review it under Customers.",
                        'type' => 'access_request',
                        'is_read' => 0,
                        'date_sent' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                AuditLog::record('access_requested', 'customer_contacts', $contact->contact_id);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request with the same email won the insert.
        }

        return $reply;
    }
}
