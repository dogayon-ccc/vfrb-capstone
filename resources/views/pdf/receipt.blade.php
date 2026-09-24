{{-- Official Receipt for one sales_transactions row. Distinct from pdf.invoice:
     invoice = what's owed on the order; receipt = proof of payment received. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'DejaVu Sans',Arial,sans-serif; font-size:11px; color:#1a1a2e; padding:30px; }
.hdr { display:table; width:100%; border-bottom:3px solid #028090; padding-bottom:16px; margin-bottom:18px; }
.hdr-l, .hdr-r { display:table-cell; vertical-align:top; }
.hdr-r { text-align:right; }
.brand { font-size:22px; font-weight:700; color:#028090; }
.sub   { font-size:9px; color:#6b7280; margin-top:3px; line-height:1.6; }
.doc   { font-size:15px; font-weight:700; }
.doc-num { font-size:13px; font-weight:700; color:#028090; margin-top:4px; }
.sec { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.1em;
       color:#028090; border-bottom:1.5px solid #e5e7eb; padding-bottom:4px; margin:18px 0 9px; }
.info { width:100%; border-collapse:collapse; }
.info td { padding:5px 6px; font-size:10px; vertical-align:top; }
.lbl { color:#6b7280; font-weight:600; width:140px; }
.val { color:#1a1a2e; font-weight:500; }
.total-row td { padding:8px 6px; font-size:13px; font-weight:700; border-top:2px solid #028090; }
.footer { margin-top:30px; font-size:9px; color:#9ca3af; text-align:center; }
</style>
</head>
<body>

<div class="hdr">
  <div class="hdr-l">
    <div class="brand">VFRB Enterprise</div>
    <div class="sub">31 San Guillermo St., Bayanan, Muntinlupa City<br/>Garment Manufacturing since 2000</div>
  </div>
  <div class="hdr-r">
    <div class="doc">Official Receipt</div>
    <div class="doc-num">{{ $txn['or_number'] ?? ('TXN-' . $txn['transaction_id']) }}</div>
  </div>
</div>

<div class="sec">Billed To</div>
<table class="info">
  <tr><td class="lbl">Customer</td><td class="val">{{ $txn['customer_name'] ?? '—' }}</td></tr>
  @if(!empty($txn['organization_name']))
  <tr><td class="lbl">Organization</td><td class="val">{{ $txn['organization_name'] }}</td></tr>
  @endif
  <tr><td class="lbl">Order</td><td class="val">#{{ $txn['order_id'] }} — {{ $txn['garment_type'] ?? 'Custom' }} × {{ $txn['quantity_ordered'] ?? '—' }}</td></tr>
</table>

<div class="sec">Payment Details</div>
<table class="info">
  <tr><td class="lbl">Payment Date</td><td class="val">{{ $txn['payment_date'] ?? '—' }}</td></tr>
  <tr><td class="lbl">Payment Method</td><td class="val">{{ ucfirst(str_replace('_',' ', $txn['payment_method'])) }}</td></tr>
  <tr><td class="lbl">Payment Terms</td><td class="val">{{ ucfirst(str_replace('_',' ', $txn['payment_terms'])) }}</td></tr>
  <tr><td class="lbl">Status</td><td class="val">{{ ucfirst($txn['completion_status']) }}</td></tr>
</table>

<div class="sec">Amount</div>
<table class="info">
  <tr><td class="lbl">Order Total</td><td class="val">₱{{ number_format($txn['amount_total'], 2) }}</td></tr>
  <tr><td class="lbl">Amount Paid</td><td class="val">₱{{ number_format($txn['amount_paid'], 2) }}</td></tr>
  <tr class="total-row"><td>Balance Due</td><td style="text-align:right">₱{{ number_format($txn['balance_due'], 2) }}</td></tr>
</table>

@if(!empty($txn['notes']))
<div class="sec">Notes</div>
<div style="font-size:10px">{{ $txn['notes'] }}</div>
@endif

<div class="footer">Printed {{ $printedAt }} · This is a system-generated receipt.</div>

</body>
</html>
