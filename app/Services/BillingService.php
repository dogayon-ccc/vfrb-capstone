<?php

// app/Services/BillingService.php
// Invoices and payment receipts. Every amount comes from a manager/staff-entered value:
//   invoice total  = orders.agreed_total (write-once, set by a manager or on the first recorded payment)
//   receipt amount = the payment amount someone typed in; balance = agreed_total - recorded payments
// Nothing here prices an order from materials, quantities or rates.

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\PaymentReceipt;
use App\Models\SalesTransaction;
use App\Support\Sql;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class BillingService
{
    /**
     * Titles depend on the manager having entered BOTH a company TIN and invoice terms in settings.
     * The TIN printed is only ever company_settings.tin; nothing else is printed as a TIN.
     */
    public function labels(?CompanySetting $s = null): array
    {
        $s ??= CompanySetting::find(1);
        $registered = $s && filled($s->tin) && filled($s->invoice_terms);

        return [
            'registered' => $registered,
            'invoice_title' => $registered ? 'Sales Invoice' : 'Billing Invoice',
            'receipt_title' => $registered ? 'Payment Receipt' : 'Payment Acknowledgement',
            'receipt_note' => $registered ? null : 'Not an official BIR receipt',
            'company_tin' => $registered ? $s->tin : null,
            'terms' => $registered ? $s->invoice_terms : null,
        ];
    }

    /** Company block for documents; falls back to the address the old PDFs already printed. */
    public function company(?CompanySetting $s = null): array
    {
        $s ??= CompanySetting::find(1);

        return [
            'name' => $s->company_name ?? 'VFRB Enterprise',
            'address' => $s?->address ?: '31 San Guillermo St., Bayanan, Muntinlupa City',
            'phone' => $s?->contact_number,
            'email' => $s?->contact_email,
        ];
    }

    /**
     * Records one payment against an order (moved unchanged from SalesTransactionController::store)
     * and writes a receipt for it. Returns ['error' => [...]] or ['order','txn','balanceDue','receipt'].
     */
    public function recordPayment(int $orderId, float $amount, string $method, ?string $terms, ?string $orNumber,
        ?string $notes, ?float $amountTotalInput, int $userId, ?string $referenceNo = null): array
    {
        return DB::transaction(function () use ($orderId, $amount, $method, $terms, $orNumber, $notes, $amountTotalInput, $userId, $referenceNo) {
            $order = DB::table('orders')
                ->where('order_id', $orderId)
                ->select('order_id', 'user_id', 'agreed_total')
                ->lockForUpdate()
                ->first();

            $existingTxn = DB::table('sales_transactions')
                ->where('order_id', $order->order_id)
                ->lockForUpdate()
                ->first();

            if (! $existingTxn) {
                // First payment. The total is the manager-set agreed_total; amount_total (manager-only, checked
                // by the caller) may set it once when it is still empty, never change it.
                if ($order->agreed_total !== null) {
                    $amountTotal = (float) $order->agreed_total;
                    if ($amountTotalInput !== null && abs($amountTotalInput - $amountTotal) > 0.004) {
                        return ['error' => [
                            'message' => 'The order total is already set to ₱'.number_format($amountTotal, 2).' and cannot be changed here.',
                            'errors' => ['amount_total' => ['Order total is already set.']],
                        ]];
                    }
                } elseif ($amountTotalInput !== null && $amountTotalInput > 0) {
                    $amountTotal = $amountTotalInput;
                    DB::table('orders')->where('order_id', $order->order_id)->update([
                        'agreed_total' => $amountTotal,
                        'updated_at' => now(),
                    ]);
                    AuditLog::record('order_total_set', 'orders', (int) $order->order_id, $userId);
                } else {
                    return ['error' => [
                        'message' => 'An Administrator must set the order total before the first payment is recorded.',
                        'errors' => ['amount_total' => ['The order total has not been set by an Administrator.']],
                    ]];
                }
                $cumulativePaid = $amount;
            } else {
                // Subsequent payment — total comes from orders.agreed_total, not the client.
                if ($order->agreed_total === null) {
                    return ['error' => ['message' => 'Record the order total on the first payment before recording additional payments.']];
                }
                $amountTotal = (float) $order->agreed_total;
                $cumulativePaid = (float) $existingTxn->amount_paid + $amount;
            }

            $balanceDue = max(0, round($amountTotal - $cumulativePaid, 2));
            $completionStatus = $balanceDue <= 0 ? 'completed' : 'processing';

            // updateOrCreate keyed on order_id — same row reused across payments.
            $txn = SalesTransaction::updateOrCreate(
                ['order_id' => $order->order_id],
                [
                    'processed_by' => $userId,
                    'amount_total' => $amountTotal,
                    'amount_paid' => $cumulativePaid,
                    'balance_due' => $balanceDue,
                    'payment_method' => $method,
                    'payment_terms' => $terms ?? 'full_payment',
                    'payment_date' => now()->toDateString(),
                    'or_number' => $orNumber,
                    'completion_status' => $completionStatus,
                    'notes' => $notes,
                    'date_processed' => now(),
                ]
            );

            $invoice = Invoice::where('order_id', $order->order_id)->live()->lockForUpdate()->first();

            // 'not_yet_paid' records terms only; no money changed hands, so no receipt.
            $receipt = null;
            if ($method !== 'not_yet_paid') {
                $receipt = $this->createNumbered(PaymentReceipt::class, 'payment_receipts', 'receipt_number', 'RCT', [
                    'order_id' => $order->order_id,
                    'invoice_id' => $invoice?->invoice_id,
                    'amount' => $amount,
                    'payment_method' => $method,
                    'reference_no' => $referenceNo,
                    'balance_after' => $balanceDue,
                    'paid_at' => now(),
                    'issued_by' => $userId,
                    'notes' => $notes,
                ]);
                AuditLog::record('payment_receipt_created', 'payment_receipts', (int) $receipt->receipt_id, $userId);
            }

            if ($invoice && $invoice->status === 'issued' && $balanceDue <= 0) {
                $invoice->update(['status' => 'paid', 'paid_at' => now(), 'marked_paid_by' => $userId]);
            }

            return ['order' => $order, 'txn' => $txn, 'balanceDue' => $balanceDue, 'receipt' => $receipt];
        });
    }

    /** Manager issues the order's invoice. Requires a manager-entered orders.agreed_total. */
    public function issue(int $orderId, int $userId, ?int $billingProfileId, ?string $dueDate, ?string $notes): array
    {
        return DB::transaction(function () use ($orderId, $userId, $billingProfileId, $dueDate, $notes) {
            $order = DB::table('orders')
                ->join('users', 'orders.user_id', '=', 'users.user_id')
                ->where('orders.order_id', $orderId)
                ->select('orders.order_id', 'orders.user_id', 'orders.status', 'orders.agreed_total',
                    'users.name', 'users.organization_name', 'users.email', 'users.address')
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return ['error' => ['Order not found.', 404]];
            }
            if ($order->status === 'cancelled') {
                return ['error' => ['A cancelled order cannot be invoiced.', 409]];
            }
            if (! $this->reachedDelivery($orderId)) {
                return ['error' => ['The invoice is issued once the order reaches Delivery. Record down payments as receipts until then.', 422]];
            }
            if ($order->agreed_total === null || (float) $order->agreed_total <= 0) {
                return ['error' => ['Set the order\'s agreed total before issuing an invoice.', 422]];
            }
            if (Invoice::where('order_id', $orderId)->live()->exists()) {
                return ['error' => ['This order already has an invoice. Void it first to reissue.', 409]];
            }

            $profile = null;
            if ($billingProfileId) {
                $profile = DB::table('billing_profiles')
                    ->where('billing_id', $billingProfileId)
                    ->where('user_id', $order->user_id)
                    ->first();
                if (! $profile) {
                    return ['error' => ['That billing profile does not belong to this customer.', 422]];
                }
            }

            $invoice = $this->createNumbered(Invoice::class, 'invoices', 'invoice_number', 'INV', [
                'order_id' => $orderId,
                'status' => 'issued',
                'billing_profile_id' => $profile?->billing_id,
                'billed_to_name' => $profile->billing_name ?? ($order->organization_name ?: $order->name),
                'billed_to_tin' => $profile->tin ?? null,
                'billed_to_address' => $profile->billing_address ?? $order->address,
                'billed_to_email' => $profile->billing_email ?? $order->email,
                'total_amount' => (float) $order->agreed_total,
                'due_date' => $dueDate,
                'notes' => $notes,
                'issued_at' => now(),
                'issued_by' => $userId,
            ]);
            AuditLog::record('invoice_issued', 'invoices', (int) $invoice->invoice_id, $userId);

            // Payments recorded before the invoice existed now point at it.
            PaymentReceipt::where('order_id', $orderId)->whereNull('invoice_id')->update(['invoice_id' => $invoice->invoice_id]);

            if ($this->balance($orderId, $invoice->total_amount) <= 0) {
                $invoice->update(['status' => 'paid', 'paid_at' => now(), 'marked_paid_by' => $userId]);
            }

            return ['invoice' => $invoice->fresh()];
        });
    }

    /** Issues the invoice when an order is delivered, using the customer's default billing profile. Null if skipped. */
    public function autoIssueOnDelivery(int $orderId, int $userId): ?Invoice
    {
        if (Invoice::where('order_id', $orderId)->live()->exists()) {
            return null;
        }
        $profileId = DB::table('billing_profiles')
            ->where('user_id', DB::table('orders')->where('order_id', $orderId)->value('user_id'))
            ->where('is_default', true)
            ->value('billing_id');

        return $this->issue($orderId, $userId, $profileId, null, 'Issued automatically on delivery.')['invoice'] ?? null;
    }

    /**
     * Manager marks an invoice paid: records the outstanding balance as one payment (with a receipt).
     * The amount is agreed_total minus payments already recorded — never typed or estimated here.
     */
    public function markPaid(Invoice $invoice, int $userId, string $method, ?string $referenceNo, ?string $notes): array
    {
        if ($invoice->status !== 'issued') {
            return ['error' => ["Invoice is {$invoice->status}.", 409]];
        }

        $balance = $this->balance($invoice->order_id, $invoice->total_amount);
        if ($balance <= 0) {
            $invoice->update(['status' => 'paid', 'paid_at' => now(), 'marked_paid_by' => $userId]);

            return ['invoice' => $invoice->fresh(), 'receipt' => null];
        }

        $hasTxn = DB::table('sales_transactions')->where('order_id', $invoice->order_id)->exists();
        $result = $this->recordPayment(
            $invoice->order_id, $balance, $method, null, null, $notes,
            $hasTxn ? null : $invoice->total_amount, $userId, $referenceNo
        );
        if (isset($result['error'])) {
            return ['error' => [$result['error']['message'], 422]];
        }

        return ['invoice' => $invoice->fresh(), 'receipt' => $result['receipt'], 'result' => $result];
    }

    public function void(Invoice $invoice): array
    {
        if ($invoice->status === 'void') {
            return ['error' => ['Invoice is already void.', 409]];
        }
        if ($invoice->status === 'paid') {
            return ['error' => ['A paid invoice cannot be voided.', 409]];
        }
        $invoice->update(['status' => 'void', 'voided_at' => now()]);
        PaymentReceipt::where('invoice_id', $invoice->invoice_id)->update(['invoice_id' => null]);

        return ['invoice' => $invoice->fresh()];
    }

    /** Delivery starts when Packing completes and its delivery record is created. */
    public function reachedDelivery(int $orderId): bool
    {
        return DB::table('delivery_tracking')->where('order_id', $orderId)->exists();
    }

    /** Recorded payments to date (sales_transactions keeps the cumulative figure). */
    public function paidToDate(int $orderId): float
    {
        return (float) (DB::table('sales_transactions')->where('order_id', $orderId)->value('amount_paid') ?? 0);
    }

    public function balance(int $orderId, float $total): float
    {
        return max(0, round($total - $this->paidToDate($orderId), 2));
    }

    /**
     * Inserts a row with the next PREFIX-YYYY-NNNN number. The unique index is the real guard:
     * on a collision the savepoint rolls back and the next number is tried.
     */
    private function createNumbered(string $model, string $table, string $column, string $prefix, array $attrs)
    {
        $year = now()->year;
        $stem = "{$prefix}-{$year}-";
        $start = strlen($stem) + 1;

        for ($attempt = 0; ; $attempt++) {
            $last = DB::table($table)->where($column, 'like', $stem.'%')
                ->orderByRaw(Sql::toInt("SUBSTRING({$column}, {$start})").' DESC')
                ->value($column);
            $number = sprintf('%s%04d', $stem, ($last ? (int) substr($last, strlen($stem)) : 0) + 1);

            try {
                return DB::transaction(fn () => $model::create($attrs + [$column => $number]));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 4) {
                    throw $e;
                }
            }
        }
    }
}
