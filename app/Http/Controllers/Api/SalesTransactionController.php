<?php
// app/Http/Controllers/Api/SalesTransactionController.php
// VFRB Enterprise — Sales Transactions (payment recording)
//
// SCHEMA VERIFIED against vfrb_db.sql:
//   sales_transactions: transaction_id (PK), order_id, processed_by,
//     amount_total, amount_paid, balance_due,
//     payment_method (enum: cash|gcash|ewallet|bank_transfer|not_yet_paid),
//     payment_terms (enum: full_payment|down_payment|net_30),
//     payment_date (date), or_number, completion_status (enum: processing|completed|cancelled),
//     notes, date_processed (timestamp)
//
// SalesTransactions.jsx sends:
//   POST: { order_id, amount_paid, payment_method, payment_terms, or_number, notes }
//   GET: returns paginated list with order + customer info
//
// PAYMENT TERMS per interview:
//   OTG/subcontract: Wednesday close → Friday bank transfer (BDO/Metrobank)
//   Direct (schools, hospitals): 80% DP + 20% on delivery
//   No consignment. No individual pieces.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SalesTransactionController extends Controller
{
    // ── GET /api/admin/transactions ───────────────────────────────────────────
    // SalesTransactions.jsx list with total summary
    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);
        $search  = $request->input('search', '');

        $query = DB::table('sales_transactions')
            ->join('orders', 'sales_transactions.order_id', '=', 'orders.order_id')
            ->join('users',  'orders.user_id', '=', 'users.user_id')
            ->join('users as staff', 'sales_transactions.processed_by', '=', 'staff.user_id')
            ->select(
                'sales_transactions.*',
                'orders.garment_type',
                'orders.quantity_ordered',
                'orders.status as order_status',
                'orders.color',
                'users.name as customer_name',
                'users.organization_name',
                'staff.name as processed_by_name'
            )
            ->orderByDesc('sales_transactions.date_processed');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'like', "%{$search}%")
                  ->orWhere('users.organization_name', 'like', "%{$search}%")
                  ->orWhere('sales_transactions.or_number', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate($perPage));
    }

    // ── GET /api/admin/transactions/{id} ──────────────────────────────────────
    public function show(int $id)
    {
        $txn = DB::table('sales_transactions')
            ->join('orders', 'sales_transactions.order_id', '=', 'orders.order_id')
            ->join('users',  'orders.user_id', '=', 'users.user_id')
            ->where('sales_transactions.transaction_id', $id)
            ->select('sales_transactions.*', 'orders.garment_type', 'orders.quantity_ordered',
                     'users.name as customer_name', 'users.organization_name')
            ->first();

        if (!$txn) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        return response()->json($txn);
    }

    // Official Receipt PDF — proof of payment, distinct from the order invoice.
    public function downloadReceiptPdf(int $id)
    {
        $txn = DB::table('sales_transactions')
            ->join('orders', 'sales_transactions.order_id', '=', 'orders.order_id')
            ->join('users',  'orders.user_id', '=', 'users.user_id')
            ->where('sales_transactions.transaction_id', $id)
            ->select('sales_transactions.*', 'orders.garment_type', 'orders.quantity_ordered',
                     'users.name as customer_name', 'users.organization_name')
            ->first();

        if (!$txn) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.receipt', [
            'txn'       => (array) $txn,
            'printedAt' => now()->format('F d, Y g:i A'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download("VFRB-Receipt-{$txn->or_number}.pdf");
    }

    // ── POST /api/admin/transactions ──────────────────────────────────────────
    // SalesTransactions.jsx payment form
    // Frontend sends: { order_id, amount_paid, payment_method, payment_terms, or_number, notes }
    // and, ONLY on an order's first-ever payment: amount_total.
    //
    // BUG-005 FIX (Aug 16 2026): sales_transactions has a UNIQUE KEY on
    // order_id (see App\Models\SalesTransaction docblock) — one row per
    // order, updated in place across payments. amount_paid on that row is
    // CUMULATIVE. The order's negotiated total lives on orders.agreed_total
    // (new column), set once on the first payment and read from there
    // (never re-taken from the request) on every payment after that.
    public function store(Request $request)
    {
        $request->validate([
            'order_id'       => 'required|integer|exists:orders,order_id',
            'amount_paid'    => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,gcash,ewallet,bank_transfer,not_yet_paid',
            'payment_terms'  => 'nullable|in:full_payment,down_payment,net_30',
            'or_number'      => 'nullable|string|max:50',
            'notes'          => 'nullable|string|max:1000',
            // Required only for an order's first payment — enforced below,
            // not here, because "required" depends on whether a
            // sales_transactions row already exists for this order.
            'amount_total'   => 'nullable|numeric|min:0.01',
        ]);

        $newAmountPaid = (float) $request->input('amount_paid');

        // Locked transaction — was a read-modify-write race on amount_paid,
        // could double-count on double-click/retry.
        $result = DB::transaction(function () use ($request, $newAmountPaid) {
            $order = DB::table('orders')
                ->where('order_id', $request->input('order_id'))
                ->select('order_id', 'user_id', 'agreed_total')
                ->lockForUpdate()
                ->first();

            $existingTxn = DB::table('sales_transactions')
                ->where('order_id', $order->order_id)
                ->lockForUpdate()
                ->first();

            if (!$existingTxn) {
                // First payment — total must be recorded now (no pricing engine here).
                $amountTotalInput = $request->input('amount_total');
                if ($amountTotalInput === null || (float) $amountTotalInput <= 0) {
                    return ['error' => [
                        'message' => 'Record the order total (amount_total) on the first payment before recording additional payments.',
                        'errors'  => ['amount_total' => ['The order total is required for an order\'s first payment.']],
                    ]];
                }
                $amountTotal = (float) $amountTotalInput;

                DB::table('orders')->where('order_id', $order->order_id)->update([
                    'agreed_total' => $amountTotal,
                    'updated_at'   => now(),
                ]);
                $cumulativePaid = $newAmountPaid;
            } else {
                // Subsequent payment — total comes from orders.agreed_total, not the client.
                if ($order->agreed_total === null) {
                    return ['error' => ['message' => 'Record the order total on the first payment before recording additional payments.']];
                }
                $amountTotal    = (float) $order->agreed_total;
                $cumulativePaid = (float) $existingTxn->amount_paid + $newAmountPaid;
            }

            $balanceDue       = max(0, round($amountTotal - $cumulativePaid, 2));
            $completionStatus = $balanceDue <= 0 ? 'completed' : 'processing';

            // updateOrCreate keyed on order_id — same row reused across payments.
            $txn = \App\Models\SalesTransaction::updateOrCreate(
                ['order_id' => $order->order_id],
                [
                    'processed_by'      => Auth::id(),
                    'amount_total'      => $amountTotal,
                    'amount_paid'       => $cumulativePaid,
                    'balance_due'       => $balanceDue,
                    'payment_method'    => $request->input('payment_method'),
                    'payment_terms'     => $request->input('payment_terms', 'full_payment'),
                    'payment_date'      => now()->toDateString(),
                    'or_number'         => $request->input('or_number'),
                    'completion_status' => $completionStatus,
                    'notes'             => $request->input('notes'),
                    'date_processed'    => now(),
                ]
            );

            return ['order' => $order, 'txn' => $txn, 'balanceDue' => $balanceDue];
        });

        if (isset($result['error'])) {
            return response()->json($result['error'], 422);
        }
        $order      = $result['order'];
        $txn        = $result['txn'];
        $balanceDue = $result['balanceDue'];

        // Notify customer of payment recorded
        $now = now();
        DB::table('notifications')->insert([
            'user_id'    => $order->user_id,
            'order_id'   => $order->order_id,
            'message'    => "Payment of ₱" . number_format($newAmountPaid, 2) . " received for Order #{$order->order_id}."
                . ($balanceDue > 0 ? " Balance due: ₱" . number_format($balanceDue, 2) . "." : " Account settled."),
            'type'       => 'payment',
            'title'      => 'Payment Received',
            'is_read'    => 0,
            'date_sent'  => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Cache::forget('dashboard_stats');

        return response()->json($txn, $txn->wasRecentlyCreated ? 201 : 200);
    }
}
