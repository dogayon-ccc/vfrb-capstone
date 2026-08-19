<?php
// app/Http/Controllers/Api/MessageController.php
// VFRB Enterprise — Order Messages (per-order threaded chat)
//
// SCHEMA VERIFIED against vfrb_db.sql:
//   order_messages: message_id (PK), order_id, sender_id,
//     body (text — NOT "message"), is_read (tinyint default 0),
//     sent_at (timestamp), created_at, updated_at
//
// CRITICAL: column is 'body' (NEVER 'message')
//
// admin/Messages.jsx:
//   GET  /api/admin/messages        → { threads: [...] }
//   GET  /api/admin/messages/{id}   → { messages: [...] }  (id = order_id)
//   POST /api/admin/messages        → { message: { ... } }
//
// customer/Messages.jsx + OrderDetail.jsx:
//   GET  /api/customer/messages/{id}  → { messages: [...] }
//   POST /api/customer/messages       → { message: { ... } }

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    // ── GET /api/admin/messages ───────────────────────────────────────────────
    // admin/Messages.jsx thread list: all orders that have messages, grouped by order
    public function adminIndex(Request $request)
    {
        // Get the latest message per order + order metadata
        $threads = DB::table('order_messages')
            ->join('orders', 'order_messages.order_id', '=', 'orders.order_id')
            ->join('users',  'orders.user_id', '=', 'users.user_id')
            ->select(
                'orders.order_id',
                'orders.garment_type',
                'orders.status',
                'orders.color',
                'users.name as customer_name',
                'users.organization_name',
                DB::raw('MAX(order_messages.sent_at) as last_message_at'),
                DB::raw('COUNT(CASE WHEN order_messages.is_read = 0 AND order_messages.sender_id != ' . Auth::id() . ' THEN 1 END) as unread_count'),
                DB::raw('(SELECT body FROM order_messages om2 WHERE om2.order_id = orders.order_id ORDER BY om2.sent_at DESC LIMIT 1) as last_body')
            )
            ->groupBy(
                'orders.order_id', 'orders.garment_type', 'orders.status',
                'orders.color', 'users.name', 'users.organization_name'
            )
            ->orderByDesc('last_message_at')
            ->paginate(20);

        return response()->json($threads);
    }

    // ── GET /api/admin/messages/{id} ──────────────────────────────────────────
    // {id} = order_id. Returns all messages for that order thread.
    public function adminShow(int $orderId)
    {
        // Mark messages from customer as read
        DB::table('order_messages')
            ->where('order_id', $orderId)
            ->where('sender_id', '!=', Auth::id())
            ->where('is_read', 0)
            ->update(['is_read' => 1, 'updated_at' => now()]);

        $messages = DB::table('order_messages')
            ->join('users', 'order_messages.sender_id', '=', 'users.user_id')
            ->where('order_messages.order_id', $orderId)
            ->select(
                'order_messages.message_id',
                'order_messages.order_id',
                'order_messages.sender_id',
                'order_messages.body',      // body — NOT message
                'order_messages.is_read',
                'order_messages.sent_at',
                'order_messages.created_at',
                'users.name as sender_name',
                'users.avatar as sender_avatar'
            )
            ->orderBy('order_messages.sent_at')
            ->get();

        return response()->json(['messages' => $messages]);
    }

    // ── POST /api/admin/messages ──────────────────────────────────────────────
    // Staff/manager sends a message
    public function adminStore(Request $request)
    {
        $request->validate([
            'order_id' => 'required|integer|exists:orders,order_id',
            'body'     => 'required|string|max:2000',  // body — NOT message
        ]);

        $id = DB::table('order_messages')->insertGetId([
            'order_id'   => $request->input('order_id'),
            'sender_id'  => Auth::id(),
            'body'       => $request->input('body'),   // body — NOT message
            'is_read'    => 0,
            'sent_at'    => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $msg = DB::table('order_messages')
            ->join('users', 'order_messages.sender_id', '=', 'users.user_id')
            ->where('order_messages.message_id', $id)
            ->select('order_messages.*', 'users.name as sender_name')
            ->first();

        // Notify the customer
        $order = DB::table('orders')
            ->where('order_id', $request->input('order_id'))
            ->select('user_id', 'garment_type')
            ->first();

        if ($order) {
            $now = now();
            DB::table('notifications')->insert([
                'user_id'    => $order->user_id,
                'order_id'   => $request->input('order_id'),
                'message'    => "New message about your order #{$request->input('order_id')} ({$order->garment_type}).",
                'type'       => 'message',
                'is_read'    => 0,
                'date_sent'  => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return response()->json(['message' => $msg], 201);
    }

    // ── GET /api/customer/messages ────────────────────────────────────────────
    // customer/Messages.jsx thread list (customer's own orders with messages)
    public function customerIndex(Request $request)
    {
        $userId  = Auth::id();
        $perPage = (int) $request->input('per_page', 20);

        $threads = DB::table('order_messages')
            ->join('orders', 'order_messages.order_id', '=', 'orders.order_id')
            ->where('orders.user_id', $userId)
            ->select(
                'orders.order_id',
                'orders.garment_type',
                'orders.status',
                'orders.color',
                DB::raw('MAX(order_messages.sent_at) as last_message_at'),
                DB::raw('COUNT(CASE WHEN order_messages.is_read = 0 AND order_messages.sender_id != ' . $userId . ' THEN 1 END) as unread_count'),
                DB::raw('(SELECT body FROM order_messages om2 WHERE om2.order_id = orders.order_id ORDER BY om2.sent_at DESC LIMIT 1) as last_body')
            )
            ->groupBy('orders.order_id', 'orders.garment_type', 'orders.status', 'orders.color')
            ->orderByDesc('last_message_at')
            ->paginate($perPage);

        return response()->json($threads);
    }

    // ── GET /api/customer/messages/{id} ───────────────────────────────────────
    // {id} = order_id. customer/Messages.jsx + OrderDetail.jsx
    public function customerShow(int $orderId)
    {
        $userId = Auth::id();

        // Verify ownership
        $orderOwned = DB::table('orders')
            ->where('order_id', $orderId)
            ->where('user_id', $userId)
            ->exists();

        if (!$orderOwned) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        // Mark staff messages as read
        DB::table('order_messages')
            ->where('order_id', $orderId)
            ->where('sender_id', '!=', $userId)
            ->where('is_read', 0)
            ->update(['is_read' => 1, 'updated_at' => now()]);

        $messages = DB::table('order_messages')
            ->join('users', 'order_messages.sender_id', '=', 'users.user_id')
            ->where('order_messages.order_id', $orderId)
            ->select(
                'order_messages.message_id',
                'order_messages.order_id',
                'order_messages.sender_id',
                'order_messages.body',      // body — NOT message
                'order_messages.is_read',
                'order_messages.sent_at',
                'users.name as sender_name',
                'users.avatar as sender_avatar'
            )
            ->orderBy('order_messages.sent_at')
            ->get();

        return response()->json(['messages' => $messages]);
    }

    // ── POST /api/customer/messages ───────────────────────────────────────────
    // customer/Messages.jsx + OrderDetail.jsx send
    public function customerStore(Request $request)
    {
        $request->validate([
            'order_id' => 'required|integer|exists:orders,order_id',
            'body'     => 'required|string|max:2000',  // body — NOT message
        ]);

        $userId  = Auth::id();
        $orderId = $request->input('order_id');

        // Verify ownership
        $orderOwned = DB::table('orders')
            ->where('order_id', $orderId)
            ->where('user_id', $userId)
            ->exists();

        if (!$orderOwned) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $id = DB::table('order_messages')->insertGetId([
            'order_id'   => $orderId,
            'sender_id'  => $userId,
            'body'       => $request->input('body'),   // body — NOT message
            'is_read'    => 0,
            'sent_at'    => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $msg = DB::table('order_messages')
            ->join('users', 'order_messages.sender_id', '=', 'users.user_id')
            ->where('order_messages.message_id', $id)
            ->select('order_messages.*', 'users.name as sender_name')
            ->first();

        // Notify all staff + managers
        $now      = now();
        $order    = DB::table('orders')->where('order_id', $orderId)->select('garment_type')->first();
        $staffIds = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->whereIn('roles.name', ['staff', 'manager'])
            ->pluck('model_has_roles.model_id');

        foreach ($staffIds as $sid) {
            DB::table('notifications')->insert([
                'user_id'    => $sid,
                'order_id'   => $orderId,
                'message'    => "New customer message on order #{$orderId} ({$order->garment_type}).",
                'type'       => 'message',
                'is_read'    => 0,
                'date_sent'  => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return response()->json(['message' => $msg], 201);
    }
}