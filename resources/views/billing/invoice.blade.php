{{-- Invoice for one order. Every amount is a recorded value: total = invoices.total_amount (frozen
     orders.agreed_total), paid = recorded payments. Unit price is that total divided by the pieces ordered;
     no line pricing is computed here. --}}
@extends('billing.layout')
@php
  $peso = fn ($v) => '₱' . number_format((float) $v, 2);
  $ord  = 'ORD-' . str_pad($order->order_id, 4, '0', STR_PAD_LEFT);
  $earlier = round($paid - $receipts->sum('amount'), 2); // payments recorded before receipts existed
  $qty  = (int) $order->quantity_ordered;
  $unit = $qty > 0 ? round($invoice->total_amount / $qty, 2) : null;
  $dpDate = $order->down_payment_received_at ? \Carbon\Carbon::parse($order->down_payment_received_at) : null;
  $dpPaid = $dpDate ? (float) $order->down_payment_amount : 0.0;
  // The down payment is recorded on the order, not in sales_transactions, so it is subtracted here as well.
  $balance = max(0, round($invoice->total_amount - $paid - $dpPaid, 2));
  $customer = $order->organization_name ?: $order->customer_name;
  $date = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('M d, Y') : '—';
@endphp

@section('title', $labels['invoice_title'] . ' ' . $invoice->invoice_number)

@section('heading')
  <div class="doc">{{ $labels['invoice_title'] }}</div>
  <div class="doc-num">{{ $invoice->invoice_number }}</div>
  <div class="meta">Order {{ $ord }}</div>
  <div class="meta">Issued {{ $invoice->issued_at->format('M d, Y') }}@if($invoice->due_date) · Due {{ $invoice->due_date->format('M d, Y') }}@endif</div>
  <div class="meta" style="margin-top:6px;">
    @php $stamp = $invoice->status === 'void' ? 'void' : ($invoice->status === 'paid' || $balance <= 0 ? 'paid' : 'issued'); @endphp
    <span class="stamp stamp-{{ $stamp }}">{{ ['void' => 'VOID', 'paid' => 'PAID', 'issued' => 'UNPAID'][$stamp] }}</span>
  </div>
@endsection

@section('body')
  <div class="sec">Billed To</div>
  <table class="kv">
    <tr><td class="k">Customer</td><td>{{ $customer ?: '—' }}@if($order->organization_name && $order->customer_name) · {{ $order->customer_name }}@endif</td></tr>
    <tr><td class="k">Billing name</td><td>{{ $invoice->billed_to_name }}</td></tr>
    @if($invoice->billed_to_address)<tr><td class="k">Billing address</td><td>{{ $invoice->billed_to_address }}</td></tr>@endif
    @if($invoice->billed_to_email)<tr><td class="k">Email</td><td>{{ $invoice->billed_to_email }}</td></tr>@endif
    <tr><td class="k">Delivery address</td><td>{{ $order->delivery_address ?: '—' }}</td></tr>
  </table>

  <div class="sec">Order</div>
  <table class="kv">
    <tr><td class="k">Order number</td><td>{{ $ord }}@if($order->po_reference) · PO {{ $order->po_reference }}@endif</td></tr>
    <tr><td class="k">Garment</td><td>{{ $order->garment_type ?: 'Custom garments' }}</td></tr>
    <tr><td class="k">Fabric type</td><td>{{ $order->fabric_type ?: '—' }}</td></tr>
    <tr><td class="k">Promised date</td><td>{{ $date($order->promised_date) }}</td></tr>
  </table>

  <div class="sec">Sizes</div>
  <table class="lines">
    <thead><tr><th>Size</th><th class="num">Quantity</th></tr></thead>
    <tbody>
      @forelse($sizes as $label => $count)
        <tr><td>{{ $label === 'CUSTOM' ? 'Custom (measured)' : $label }}</td><td class="num">{{ number_format($count) }} pcs</td></tr>
      @empty
        <tr><td>All sizes</td><td class="num">{{ number_format($qty) }} pcs</td></tr>
      @endforelse
      <tr><td style="font-weight:700;">Total pieces</td><td class="num" style="font-weight:700;">{{ number_format($qty) }} pcs</td></tr>
    </tbody>
  </table>

  <table class="totals">
    <tr><td>Unit price</td><td class="num">{{ $unit !== null ? $peso($unit) : '—' }}</td></tr>
    <tr><td>Total ({{ number_format($qty) }} pcs)</td><td class="num">{{ $peso($invoice->total_amount) }}</td></tr>
    <tr><td>Down payment paid</td><td class="num">{{ $dpDate ? '− ' . $peso($dpPaid) : 'Not yet received' }}</td></tr>
    <tr><td>Other payments received</td><td class="num">− {{ $peso($paid) }}</td></tr>
    <tr class="grand"><td>Balance due</td><td class="num">{{ $peso($balance) }}</td></tr>
  </table>

  <div class="sec">Payment Dates</div>
  <table class="lines">
    <thead><tr><th>Payment</th><th>Date</th><th class="hide-sm">Method</th><th class="num">Amount</th></tr></thead>
    <tbody>
      @if($dpDate)
        <tr><td>Down payment</td><td>{{ $dpDate->format('M d, Y') }}</td><td class="hide-sm">—</td><td class="num">{{ $peso($dpPaid) }}</td></tr>
      @endif
      @if($earlier > 0)
        <tr><td>Recorded before receipts</td><td>—</td><td class="hide-sm">—</td><td class="num">{{ $peso($earlier) }}</td></tr>
      @endif
      @foreach($receipts as $r)
        <tr>
          <td>{{ $r->receipt_number }}</td>
          <td>{{ $r->paid_at->format('M d, Y') }}</td>
          <td class="hide-sm">{{ ucwords(str_replace('_', ' ', $r->payment_method)) }}</td>
          <td class="num">{{ $peso($r->amount) }}</td>
        </tr>
      @endforeach
      @if(!$dpDate && $earlier <= 0 && $receipts->isEmpty())
        <tr><td colspan="4" style="color:#6b7280;">No payments recorded yet.</td></tr>
      @endif
    </tbody>
  </table>

  @if($invoice->notes)
    <div class="sec">Notes</div>
    <div>{{ $invoice->notes }}</div>
  @endif
@endsection

@section('footnote')
  @if(!$labels['registered'])Billing statement for the order above.@endif
@endsection
