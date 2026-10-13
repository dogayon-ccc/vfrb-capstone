{{-- Acknowledges one recorded payment. Amount and balance are exactly what was recorded. --}}
@extends('billing.layout')
@php
  $peso = fn ($v) => '₱' . number_format((float) $v, 2);
  $ord  = 'ORD-' . str_pad($order->order_id, 4, '0', STR_PAD_LEFT);
@endphp

@section('title', $labels['receipt_title'] . ' ' . $receipt->receipt_number)

@section('heading')
  <div class="doc">{{ $labels['receipt_title'] }}</div>
  @if($labels['receipt_note'])<div class="note">{{ $labels['receipt_note'] }}</div>@endif
  <div class="doc-num">{{ $receipt->receipt_number }}</div>
  <div class="meta">{{ $receipt->paid_at->format('M d, Y g:i A') }}</div>
@endsection

@section('body')
  <div class="sec">Amount Received</div>
  <div class="big">{{ $peso($receipt->amount) }}</div>

  <div class="sec">Payment</div>
  <table class="kv">
    <tr><td class="k">Received from</td><td>{{ $payer }}</td></tr>
    <tr><td class="k">For</td><td>{{ $ord }} — {{ $order->garment_type ?: 'Custom garments' }} × {{ number_format((int) $order->quantity_ordered) }} pcs</td></tr>
    @if($invoice)<tr><td class="k">Invoice</td><td>{{ $invoice->invoice_number }}</td></tr>@endif
    <tr><td class="k">Method</td><td>{{ ucwords(str_replace('_', ' ', $receipt->payment_method)) }}</td></tr>
    @if($receipt->reference_no)<tr><td class="k">Reference no.</td><td>{{ $receipt->reference_no }}</td></tr>@endif
    <tr><td class="k">Balance after payment</td><td>{{ $peso($receipt->balance_after) }}</td></tr>
    @if($receivedBy)<tr><td class="k">Received by</td><td>{{ $receivedBy }}</td></tr>@endif
  </table>

  @if($receipt->notes)
    <div class="sec">Notes</div>
    <div>{{ $receipt->notes }}</div>
  @endif
@endsection

@section('footnote')
  @if($labels['receipt_note']){{ $labels['receipt_note'] }}. @endif
@endsection
