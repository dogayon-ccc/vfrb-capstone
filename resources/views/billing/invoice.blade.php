{{-- Invoice for one order. Every amount is a recorded value: total = invoices.total_amount (frozen
     orders.agreed_total), paid = recorded payments. No line pricing is computed here. --}}
@extends('billing.layout')
@php
  $peso = fn ($v) => '₱' . number_format((float) $v, 2);
  $ord  = 'ORD-' . str_pad($order->order_id, 4, '0', STR_PAD_LEFT);
  $earlier = round($paid - $receipts->sum('amount'), 2); // payments recorded before receipts existed
@endphp

@section('title', $labels['invoice_title'] . ' ' . $invoice->invoice_number)

@section('heading')
  <div class="doc">{{ $labels['invoice_title'] }}</div>
  <div class="doc-num">{{ $invoice->invoice_number }}</div>
  <div class="meta">Issued {{ $invoice->issued_at->format('M d, Y') }}@if($invoice->due_date) · Due {{ $invoice->due_date->format('M d, Y') }}@endif</div>
  <div class="meta" style="margin-top:6px;">
    @php $stamp = $invoice->status === 'void' ? 'void' : ($invoice->status === 'paid' || $balance <= 0 ? 'paid' : 'issued'); @endphp
    <span class="stamp stamp-{{ $stamp }}">{{ ['void' => 'VOID', 'paid' => 'PAID', 'issued' => 'UNPAID'][$stamp] }}</span>
  </div>
@endsection

@section('body')
  <div class="sec">Billed To</div>
  <table class="kv">
    <tr><td class="k">Name</td><td>{{ $invoice->billed_to_name }}</td></tr>
    @if($invoice->billed_to_address)<tr><td class="k">Address</td><td>{{ $invoice->billed_to_address }}</td></tr>@endif
    @if($invoice->billed_to_email)<tr><td class="k">Email</td><td>{{ $invoice->billed_to_email }}</td></tr>@endif
    <tr><td class="k">Order</td><td>{{ $ord }}@if($order->po_reference) · PO {{ $order->po_reference }}@endif</td></tr>
  </table>

  <div class="sec">Details</div>
  <table class="lines">
    <thead><tr><th>Description</th><th class="num hide-sm">Qty</th><th class="num">Amount</th></tr></thead>
    <tbody>
      <tr>
        <td>{{ $order->garment_type ?: 'Custom garments' }} — {{ $ord }}</td>
        <td class="num hide-sm">{{ number_format((int) $order->quantity_ordered) }} pcs</td>
        <td class="num">{{ $peso($invoice->total_amount) }}</td>
      </tr>
    </tbody>
  </table>

  <table class="totals">
    <tr><td>Agreed total</td><td class="num">{{ $peso($invoice->total_amount) }}</td></tr>
    <tr><td>Payments received</td><td class="num">− {{ $peso($paid) }}</td></tr>
    <tr class="grand"><td>Balance due</td><td class="num">{{ $peso($balance) }}</td></tr>
  </table>

  @if($receipts->isNotEmpty() || $earlier > 0)
    <div class="sec">Payments</div>
    <table class="lines">
      <thead><tr><th>Receipt</th><th class="hide-sm">Date</th><th class="hide-sm">Method</th><th class="num">Amount</th></tr></thead>
      <tbody>
        @if($earlier > 0)
          <tr><td>Recorded before receipts</td><td class="hide-sm">—</td><td class="hide-sm">—</td><td class="num">{{ $peso($earlier) }}</td></tr>
        @endif
        @foreach($receipts as $r)
          <tr>
            <td>{{ $r->receipt_number }}</td>
            <td class="hide-sm">{{ $r->paid_at->format('M d, Y') }}</td>
            <td class="hide-sm">{{ ucwords(str_replace('_', ' ', $r->payment_method)) }}</td>
            <td class="num">{{ $peso($r->amount) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  @if($invoice->notes)
    <div class="sec">Notes</div>
    <div>{{ $invoice->notes }}</div>
  @endif
@endsection

@section('footnote')
  @if(!$labels['registered'])Billing statement for the order above.@endif
@endsection
