{{-- resources/views/pdf/invoice.blade.php --}}
{{-- VFRB Enterprise — Order Invoice · barryvdh/laravel-dompdf --}}
{{-- Adapted from the orphaned resources/views/pdf/receipt.blade.php (never wired to a route).
     That template referenced material_formulas / formula_id / chest / scale_ratio / TESDA NC II /
     SAP CS12 & F-28 transaction codes — none of which exist in this project. The migrations
     history log confirms create_material_formulas_table ran once, early in the project, and was
     later dropped — this schema deliberately never went back. Every field below is checked
     directly against vfrb_db.sql and OrderController::adminShow()'s actual response shape. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'DejaVu Sans',Arial,sans-serif; font-size:11px; color:#1a1a2e; background:#fff; padding:30px; }

.hdr { display:table; width:100%; border-bottom:3px solid #028090; padding-bottom:16px; margin-bottom:18px; }
.hdr-l { display:table-cell; vertical-align:top; }
.hdr-r { display:table-cell; vertical-align:top; text-align:right; }
.brand { font-size:22px; font-weight:700; color:#028090; letter-spacing:-.5px; }
.sub   { font-size:9px; color:#6b7280; margin-top:3px; line-height:1.6; }
.doc   { font-size:15px; font-weight:700; color:#1a1a2e; }
.doc-num { font-size:13px; font-weight:700; color:#028090; margin-top:4px; }

.sec { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.1em;
       color:#028090; border-bottom:1.5px solid #e5e7eb; padding-bottom:4px; margin:18px 0 9px; }

.info { width:100%; border-collapse:collapse; }
.info td { padding:5px 6px; font-size:10px; vertical-align:top; }
.lbl { color:#6b7280; font-weight:600; width:120px; }
.val { color:#1a1a2e; font-weight:500; }

.badge { display:inline-block; padding:2px 8px; border-radius:3px; font-size:8px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; }
.s-pending    { background:#fef3c7; color:#d97706; }
.s-confirmed  { background:#dbeafe; color:#1d4ed8; }
.s-pattern    { background:#ede9fe; color:#7c3aed; }
.s-segregation{ background:#ede9fe; color:#6d28d9; }
.s-cutting    { background:#ede9fe; color:#7c3aed; }
.s-sewing     { background:#cffafe; color:#0e7490; }
.s-qc         { background:#ffedd5; color:#c2410c; }
.s-pressing   { background:#fef3c7; color:#b45309; }
.s-packing    { background:#fef3c7; color:#d97706; }
.s-completed  { background:#d1fae5; color:#065f46; }
.s-cancelled  { background:#fee2e2; color:#dc2626; }

.pipe { display:table; width:100%; margin:10px 0 14px; }
.pip-step { display:table-cell; text-align:center; vertical-align:top; }
.pip-line  { display:table-cell; vertical-align:middle; width:24px; }
.pip-line div { height:2px; }
.dot { width:18px; height:18px; border-radius:50%; display:inline-block;
       font-size:7px; font-weight:700; color:#fff; line-height:18px; text-align:center; margin-bottom:3px; }
.dot-done   { background:#028090; }
.dot-active { background:#f59e0b; }
.dot-pend   { background:#d1d5db; color:#6b7280; }
.l-done { background:#028090; }
.l-pend { background:#d1d5db; }
.pip-label { font-size:6.5px; color:#6b7280; text-transform:uppercase; letter-spacing:.03em; display:block; }

.bom { width:100%; border-collapse:collapse; font-size:10px; margin-top:6px; }
.bom thead th {
  background:#f0fafa; color:#028090; font-size:8px; font-weight:700;
  text-transform:uppercase; letter-spacing:.06em; padding:7px 8px;
  border-bottom:2px solid #028090; text-align:left;
}
.bom tbody tr:nth-child(even) { background:#f9fafb; }
.bom tbody td { padding:6px 8px; border-bottom:1px solid #f3f4f6; }
.t-cat { background:#f0fafa; color:#028090; font-size:8px; font-weight:700;
         padding:1px 6px; border-radius:3px; text-transform:uppercase; letter-spacing:.04em; }

.ai { background:#f0fafa; border:1px solid #b2dfdb; border-left:4px solid #028090;
      padding:10px 14px; border-radius:3px; margin-top:12px; }
.ai-title { font-size:10px; font-weight:700; color:#028090; margin-bottom:5px; }
.ai-row   { font-size:9px; color:#374151; line-height:1.65; margin-top:3px; }
.ai-row strong { color:#028090; }

.pay { display:table; width:100%; margin-top:6px; }
.pay-l { display:table-cell; width:50%; padding-right:12px; }
.pay-r { display:table-cell; width:50%; }
.amount-box { background:#f0fafa; border:1px solid #b2dfdb; border-radius:4px; padding:12px 14px; }
.amount-row { display:table; width:100%; margin-bottom:4px; }
.amount-lbl { display:table-cell; font-size:10px; color:#6b7280; }
.amount-val { display:table-cell; text-align:right; font-size:11px; font-weight:700; color:#1a1a2e; }
.amount-total-row { display:table; width:100%; border-top:1.5px solid #028090; padding-top:6px; margin-top:4px; }
.amount-total-lbl { display:table-cell; font-size:11px; font-weight:700; color:#028090; }
.amount-total-val { display:table-cell; text-align:right; font-size:14px; font-weight:700; color:#028090; }

.foot { margin-top:24px; padding-top:12px; border-top:1px solid #e5e7eb; display:table; width:100%; }
.foot-l { display:table-cell; font-size:8.5px; color:#9ca3af; line-height:1.7; }
.foot-r { display:table-cell; text-align:right; font-size:8.5px; color:#9ca3af; line-height:1.7; }
</style>
</head>
<body>

{{-- HEADER --}}
@php $txn = $order['transactions'][0] ?? null; @endphp
<div class="hdr">
  <div class="hdr-l">
    <div class="brand">VFRB Enterprise</div>
    <div class="sub">
      #31 San Guillermo St., Bayanan, Muntinlupa City<br>
      Garment Manufacturer — Bulk / Institutional Orders
    </div>
  </div>
  <div class="hdr-r">
    <div class="doc">Order Invoice</div>
    <div class="doc-num">ORD-{{ str_pad($order['order_id'] ?? 0, 4, '0', STR_PAD_LEFT) }}</div>
    <div style="font-size:9px;color:#6b7280;margin-top:4px;">Printed: {{ $printedAt }}</div>
    @if(!empty($txn['or_number']))
      <div style="font-size:9px;color:#6b7280;">OR# {{ $txn['or_number'] }}</div>
    @endif
  </div>
</div>

{{-- PIPELINE — matches orders.status enum exactly --}}
@php
  $stages  = ['pending','confirmed','pattern','segregation','cutting','sewing','qc','pressing','packing','completed'];
  $labels  = ['Pending','Confirmed','Pattern','Segregation','Cutting','Sewing','QC','Pressing','Packing','Done'];
  $current = $order['status'] ?? 'pending';
  $curIdx  = array_search($current, $stages);
  if ($curIdx === false) $curIdx = -1;
@endphp
@if($current !== 'cancelled')
<div class="sec">Production Pipeline</div>
<div class="pipe">
  @foreach($stages as $i => $s)
    @if($i > 0)
      <div class="pip-line">
        <div class="{{ $i <= $curIdx ? 'l-done' : 'l-pend' }}"></div>
      </div>
    @endif
    <div class="pip-step">
      <div class="dot {{ $i < $curIdx ? 'dot-done' : ($i == $curIdx ? 'dot-active' : 'dot-pend') }}">
        {{ $i < $curIdx ? '✓' : ($i + 1) }}
      </div>
      <span class="pip-label">{{ $labels[$i] }}</span>
    </div>
  @endforeach
</div>
@endif

{{-- ORDER DETAILS --}}
<div class="sec">Order Information</div>
<table class="info">
  <tr>
    <td class="lbl">Customer</td>
    <td class="val">{{ $order['user']['name'] ?? '—' }}</td>
    <td class="lbl">Garment</td>
    <td class="val">{{ $order['garment_type'] ?? '—' }}</td>
  </tr>
  <tr>
    <td class="lbl">Email</td>
    <td class="val">{{ $order['user']['email'] ?? '—' }}</td>
    <td class="lbl">Quantity</td>
    <td class="val">{{ $order['quantity_ordered'] ?? '—' }} pieces</td>
  </tr>
  <tr>
    <td class="lbl">Organization</td>
    <td class="val">{{ $order['user']['organization_name'] ?: '(Individual)' }}</td>
    <td class="lbl">Color</td>
    <td class="val">{{ $order['color'] ?? '—' }}</td>
  </tr>
  <tr>
    <td class="lbl">Order Date</td>
    <td class="val">
      {{ !empty($order['created_at']) ? \Carbon\Carbon::parse($order['created_at'])->format('F d, Y') : '—' }}
    </td>
    <td class="lbl">Collar / Sleeve</td>
    <td class="val">{{ $order['collar_type'] ?? '—' }} / {{ $order['sleeve_type'] ?? '—' }}</td>
  </tr>
  <tr>
    <td class="lbl">Status</td>
    <td class="val"><span class="badge s-{{ $current }}">{{ ucfirst($current) }}</span></td>
    <td class="lbl">Order Type</td>
    <td class="val" style="text-transform:capitalize;">{{ str_replace('_',' ',$order['order_type'] ?? '—') }}</td>
  </tr>
  @if(!empty($order['po_reference']))
  <tr>
    <td class="lbl">Client PO#</td>
    <td class="val" colspan="3">{{ $order['po_reference'] }}</td>
  </tr>
  @endif
  @if(!empty($order['client_design_notes']))
  <tr>
    <td class="lbl">Design Notes</td>
    <td class="val" colspan="3">{{ $order['client_design_notes'] }}</td>
  </tr>
  @endif
  @if(!empty($order['negotiated_delivery_date']) || !empty($order['target_delivery_date']))
  <tr>
    <td class="lbl">Delivery Date</td>
    <td class="val" colspan="3">
      {{ \Carbon\Carbon::parse($order['negotiated_delivery_date'] ?? $order['target_delivery_date'])->format('F d, Y') }}
      @if(!empty($order['negotiated_delivery_date']) && !empty($order['target_delivery_date']))
        <span style="color:#9ca3af;">(originally requested {{ \Carbon\Carbon::parse($order['target_delivery_date'])->format('M d, Y') }})</span>
      @endif
    </td>
  </tr>
  @endif
</table>

{{-- DESIGN REFERENCE — NEW (Aug 14 2026), additive only.
     dompdf can't reliably fetch remote/CDN URLs, so this uses a local
     filesystem path (storage_path) rather than getStorageUrl()'s public
     HTTP URL, which is a frontend-only helper. Guarded with file_exists()
     so a missing/moved file never breaks PDF generation — falls through
     to nothing rendered rather than a broken image or fatal error.
     studio_config (Design Studio orders) has no server-side render here —
     dompdf can't execute WebGL/Three.js, so that stays text-only in this
     document; the on-screen Admin Order Detail page is where that visual
     preview actually renders. --}}
@php
  $refFile = $order['client_design_ref_file'] ?? null;
  $refPath = $refFile ? storage_path('app/public/'.$refFile) : null;
  $refIsImg = $refFile && preg_match('/\.(jpe?g|png|gif|webp)$/i', $refFile);
@endphp
@if($refPath && $refIsImg && file_exists($refPath))
<div class="sec">Design Reference</div>
<div style="text-align:center;margin:8px 0 4px;">
  <img src="{{ $refPath }}" style="max-width:280px;max-height:280px;border:1px solid #e5e7eb;border-radius:4px;">
</div>
@endif

{{-- BOM TABLE — real material_recommendations, deterministic rate engine --}}
@if(!empty($order['recommendations']) && count($order['recommendations']) > 0)
<div class="sec">Bill of Materials</div>
<table class="bom">
  <thead>
    <tr>
      <th style="width:34%">Material</th>
      <th style="width:18%">Category</th>
      <th style="width:30%">Estimated Amount</th>
      <th style="width:18%">Linked Stock</th>
    </tr>
  </thead>
  <tbody>
    @foreach($order['recommendations'] as $r)
    <tr>
      <td style="font-weight:500;">{{ $r['material_name'] ?? '—' }}</td>
      <td><span class="t-cat">{{ $r['category'] ?? 'Other' }}</span></td>
      <td>{{ $r['estimated_range'] ?? '—' }}</td>
      <td>
        @if(!empty($r['material']['material_name']))
          {{ number_format((float)($r['material']['quantity_in_stock'] ?? 0), 2) }} {{ $r['material']['unit'] ?? '' }} in stock
        @else
          Not yet linked
        @endif
      </td>
    </tr>
    @endforeach
  </tbody>
</table>

<div class="ai">
  <div class="ai-title">🤖 Material Recommendation — Deterministic, Not AI-Generated</div>
  <div class="ai-row">
    Quantities come from a staff-configured usage rate (material × garment type), not from the AI.
    Google Gemini is used only to explain the recommendation in plain language — it never computes
    the numbers. Same rate, same order quantity → same result, every time.
  </div>
</div>
@elseif(($order['order_type'] ?? '') === 'subcontract')
<div class="ai" style="border-left-color:#7c3aed;background:#faf5ff;margin-top:14px;">
  <div class="ai-title" style="color:#7c3aed;">🏭 Subcontract Order — Materials supplied by partner</div>
  <div class="ai-row">VFRB Enterprise provides sewing, pressing, and packing only. Materials are partner-supplied.</div>
</div>
@endif

{{-- PAYMENT --}}
@if($txn)
<div class="sec">Payment Summary</div>
<div class="pay">
  <div class="pay-l">
    <table class="info">
      <tr><td class="lbl">Payment Method</td><td class="val">{{ ucwords(str_replace('_',' ',$txn['payment_method'] ?? '—')) }}</td></tr>
      <tr><td class="lbl">Payment Terms</td><td class="val">{{ ucwords(str_replace('_',' ',$txn['payment_terms'] ?? '—')) }}</td></tr>
      <tr><td class="lbl">Payment Date</td><td class="val">{{ !empty($txn['payment_date']) ? \Carbon\Carbon::parse($txn['payment_date'])->format('M d, Y') : 'Pending' }}</td></tr>
      <tr><td class="lbl">OR Number</td><td class="val">{{ $txn['or_number'] ?? '—' }}</td></tr>
    </table>
  </div>
  <div class="pay-r">
    <div class="amount-box">
      <div class="amount-row"><span class="amount-lbl">Amount Total</span><span class="amount-val">₱{{ number_format($txn['amount_total'] ?? 0, 2) }}</span></div>
      <div class="amount-row"><span class="amount-lbl">Amount Paid</span><span class="amount-val">₱{{ number_format($txn['amount_paid'] ?? 0, 2) }}</span></div>
      <div class="amount-total-row"><span class="amount-total-lbl">Balance Due</span><span class="amount-total-val">₱{{ number_format($txn['balance_due'] ?? 0, 2) }}</span></div>
    </div>
  </div>
</div>
@else
<div class="sec">Payment Summary</div>
<p style="font-size:10px;color:#9ca3af;">No payment recorded yet for this order.</p>
@endif

{{-- FOOTER --}}
<div class="foot">
  <div class="foot-l">
    <strong>VFRB Enterprise</strong> — AI-Enabled Sales &amp; Inventory Management System<br>
    with Raw Materials Recommendation · City College of Calamba, BSIT Capstone 2026
  </div>
  <div class="foot-r">
    Printed: {{ $printedAt }}<br>
    ORD-{{ str_pad($order['order_id'] ?? 0, 4, '0', STR_PAD_LEFT) }}<br>
    <span style="color:#028090;font-weight:700;">VFRB Enterprise</span>
  </div>
</div>
</body>
</html>
