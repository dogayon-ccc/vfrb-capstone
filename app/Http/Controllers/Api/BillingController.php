<?php
// app/Http/Controllers/Api/BillingController.php
// Invoices (INV-YYYY-NNNN) and payment receipts (RCT-YYYY-NNNN).
//   manager:        issue / mark-paid / void an order's invoice
//   staff+manager:  read an order's billing, open any invoice/receipt document
//   customer:       read billing + open documents for their own orders only
// Documents render as an A4 PDF (?format=pdf, default) or a phone-friendly HTML page (?format=html)
// from the same Blade view. Titles follow BillingService::labels().
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentReceipt;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    public function __construct(private BillingService $billing) {}

    // ── GET /api/admin/orders/{id}/billing ───────────────────────────────────
    public function adminOrderBilling(int $id)
    {
        if (!DB::table('orders')->where('order_id', $id)->exists()) {
            return response()->json(['message' => 'Order not found.'], 404);
        }
        return response()->json($this->billingSummary($id, true));
    }

    // ── POST /api/admin/orders/{id}/invoice (manager) ────────────────────────
    public function issue(Request $request, int $id)
    {
        $data = $request->validate([
            'billing_profile_id' => 'nullable|integer',
            'due_date'           => 'nullable|date|after_or_equal:today',
            'notes'              => 'nullable|string|max:1000',
        ]);

        $result = $this->billing->issue($id, Auth::id(), $data['billing_profile_id'] ?? null, $data['due_date'] ?? null, $data['notes'] ?? null);
        if (isset($result['error'])) {
            return response()->json(['message' => $result['error'][0]], $result['error'][1]);
        }

        $invoice = $result['invoice'];
        $this->notifyCustomer($id, 'Invoice Issued', "{$invoice->invoice_number} for Order #{$id} is ready to view and download.");
        Cache::forget('dashboard_stats');

        return response()->json(['invoice' => $invoice, 'billing' => $this->billingSummary($id, true)], 201);
    }

    // ── PATCH /api/admin/invoices/{id}/mark-paid (manager) ───────────────────
    public function markPaid(Request $request, int $id)
    {
        $data = $request->validate([
            'payment_method' => 'required|in:cash,gcash,ewallet,bank_transfer',
            'reference_no'   => 'nullable|string|max:50',
            'notes'          => 'nullable|string|max:1000',
        ]);

        $invoice = Invoice::findOrFail($id);
        $result  = $this->billing->markPaid($invoice, Auth::id(), $data['payment_method'], $data['reference_no'] ?? null, $data['notes'] ?? null);
        if (isset($result['error'])) {
            return response()->json(['message' => $result['error'][0]], $result['error'][1]);
        }

        if ($result['receipt']) {
            $this->notifyCustomer($invoice->order_id, 'Payment Received',
                'Payment of ₱' . number_format($result['receipt']->amount, 2) . " received for Order #{$invoice->order_id}. Account settled.");
        }
        Cache::forget('dashboard_stats');

        return response()->json(['invoice' => $result['invoice'], 'receipt' => $result['receipt'], 'billing' => $this->billingSummary($invoice->order_id, true)]);
    }

    // ── PATCH /api/admin/invoices/{id}/void (manager) ────────────────────────
    public function void(int $id)
    {
        $result = $this->billing->void(Invoice::findOrFail($id));
        if (isset($result['error'])) {
            return response()->json(['message' => $result['error'][0]], $result['error'][1]);
        }
        return response()->json(['invoice' => $result['invoice'], 'billing' => $this->billingSummary($result['invoice']->order_id, true)]);
    }

    // ── GET /api/admin/invoices/{id}/document  ·  /api/admin/receipts/{id}/document ──
    public function adminInvoiceDocument(Request $request, int $id)
    {
        return $this->invoiceDocument($request, Invoice::findOrFail($id));
    }

    public function adminReceiptDocument(Request $request, int $id)
    {
        return $this->receiptDocument($request, PaymentReceipt::findOrFail($id));
    }

    // ── Customer: own orders only ────────────────────────────────────────────
    // GET /api/customer/orders/{id}/billing
    public function customerOrderBilling(int $id)
    {
        if (!$this->ownsOrder($id)) {
            return response()->json(['message' => 'Order not found.'], 404);
        }
        return response()->json($this->billingSummary($id, false));
    }

    // GET /api/customer/invoices/{id}/document — voided invoices are not shown to customers.
    public function customerInvoiceDocument(Request $request, int $id)
    {
        $invoice = Invoice::live()->find($id);
        if (!$invoice || !$this->ownsOrder($invoice->order_id)) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }
        return $this->invoiceDocument($request, $invoice);
    }

    // GET /api/customer/receipts/{id}/document
    public function customerReceiptDocument(Request $request, int $id)
    {
        $receipt = PaymentReceipt::find($id);
        if (!$receipt || !$this->ownsOrder($receipt->order_id)) {
            return response()->json(['message' => 'Receipt not found.'], 404);
        }
        return $this->receiptDocument($request, $receipt);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────
    private function ownsOrder(int $orderId): bool
    {
        return DB::table('orders')->where('order_id', $orderId)->where('user_id', Auth::id())->exists();
    }

    /** What Order Detail needs: the live invoice, its receipts, and totals from recorded values only. */
    private function billingSummary(int $orderId, bool $admin): array
    {
        $order   = DB::table('orders')->where('order_id', $orderId)->select('agreed_total', 'status')->first();
        $invoice = Invoice::where('order_id', $orderId)->live()->first();
        $total   = $invoice?->total_amount ?? ($order->agreed_total !== null ? (float) $order->agreed_total : null);
        $paid    = $this->billing->paidToDate($orderId);

        $out = [
            'invoice'      => $invoice?->only(['invoice_id', 'invoice_number', 'status', 'total_amount', 'issued_at', 'due_date', 'paid_at', 'billed_to_name']),
            'receipts'     => PaymentReceipt::where('order_id', $orderId)->orderBy('paid_at')
                ->get(['receipt_id', 'receipt_number', 'amount', 'payment_method', 'reference_no', 'balance_after', 'paid_at']),
            'total'        => $total,
            'paid_to_date' => $paid,
            'balance'      => $total !== null ? max(0, round($total - $paid, 2)) : null,
            'labels'       => collect($this->billing->labels())->only(['invoice_title', 'receipt_title', 'receipt_note'])->all(),
        ];

        if ($admin) {
            $out['awaiting_delivery'] = !$invoice && !$this->billing->reachedDelivery($orderId);
            $out['can_issue']  = !$invoice && !$out['awaiting_delivery'] && $order->status !== 'cancelled' && $order->agreed_total !== null;
            $out['void_count'] = Invoice::where('order_id', $orderId)->where('status', 'void')->count();
            $out['billing_profiles'] = DB::table('billing_profiles')
                ->where('user_id', DB::table('orders')->where('order_id', $orderId)->value('user_id'))
                ->orderByDesc('is_default')
                ->get(['billing_id', 'billing_name', 'billing_address', 'is_default']);
        }
        return $out;
    }

    private function invoiceDocument(Request $request, Invoice $invoice)
    {
        $order = DB::table('orders')->join('users', 'orders.user_id', '=', 'users.user_id')
            ->where('orders.order_id', $invoice->order_id)
            ->select('orders.order_id', 'orders.garment_type', 'orders.quantity_ordered', 'orders.po_reference',
                     'orders.fabric_type', 'orders.delivery_address', 'orders.promised_date',
                     'orders.down_payment_amount', 'orders.down_payment_received_at',
                     'users.name as customer_name', 'users.organization_name')
            ->first();

        // Per-size quantities as the customer submitted them (measurements.size_label, qty).
        $sizes = DB::table('measurements')->where('order_id', $invoice->order_id)
            ->whereNotNull('size_label')->where('qty', '>', 0)
            ->orderBy('measurement_id')
            ->get(['size_label', 'qty'])
            ->groupBy(fn ($m) => strtoupper($m->size_label))
            ->map(fn ($rows) => (int) $rows->sum('qty'));

        return $this->render($request, 'billing.invoice', "{$invoice->invoice_number}", [
            'invoice'  => $invoice,
            'order'    => $order,
            'sizes'    => $sizes,
            'receipts' => PaymentReceipt::where('order_id', $invoice->order_id)->orderBy('paid_at')->get(),
            'paid'     => $this->billing->paidToDate($invoice->order_id),
            'balance'  => $this->billing->balance($invoice->order_id, $invoice->total_amount),
        ]);
    }

    private function receiptDocument(Request $request, PaymentReceipt $receipt)
    {
        $invoice = $receipt->invoice_id ? Invoice::find($receipt->invoice_id) : null;
        $order   = DB::table('orders')->join('users', 'orders.user_id', '=', 'users.user_id')
            ->where('orders.order_id', $receipt->order_id)
            ->select('orders.order_id', 'orders.garment_type', 'orders.quantity_ordered', 'users.name', 'users.organization_name')
            ->first();

        return $this->render($request, 'billing.receipt', $receipt->receipt_number, [
            'receipt'    => $receipt,
            'invoice'    => $invoice,
            'order'      => $order,
            'receivedBy' => DB::table('users')->where('user_id', $receipt->issued_by)->value('name'),
            'payer'      => $invoice->billed_to_name ?? ($order->organization_name ?: $order->name),
        ]);
    }

    private function render(Request $request, string $view, string $filename, array $data)
    {
        $data += [
            'labels'    => $this->billing->labels(),
            'company'   => $this->billing->company(),
            'printedAt' => now()->format('F d, Y g:i A'),
        ];

        if ($request->query('format') === 'html') {
            return response(view($view, $data + ['mode' => 'html'])->render(), 200, [
                'Content-Type'            => 'text/html; charset=UTF-8',
                // Narrower than the API default (default-src 'none'), which would block the inline styles.
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'",
            ]);
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView($view, $data + ['mode' => 'pdf'])
            ->setPaper('a4', 'portrait')
            ->download("VFRB-{$filename}.pdf");
    }

    private function notifyCustomer(int $orderId, string $title, string $message): void
    {
        $userId = DB::table('orders')->where('order_id', $orderId)->value('user_id');
        $now    = now();
        DB::table('notifications')->insert([
            'user_id' => $userId, 'order_id' => $orderId, 'message' => $message, 'type' => 'payment',
            'title' => $title, 'is_read' => 0, 'date_sent' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
