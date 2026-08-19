{{-- resources/views/pdf/receipt.blade.php --}}
{{-- VFRB Enterprise — Production Receipt · barryvdh/laravel-dompdf --}}
{{-- FIXES v10:
       Line ~110: (TESDA NC II M-baseline = 96 cm) → (VFRB Proprietary M-baseline = 96 cm)
       Line ~148: Custom sizing (TESDA NC II) → Custom sizing (VFRB Proprietary Parametric Formula)
       Pipeline stages: updated to 7 stages (added segregation + pressing)
--}}
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
.t-qty  { font-weight:700; color:#028090; font-family:monospace; }
.t-fid  { background:#f0fafa; color:#028090; font-size:8px; font-weight:700;
          padding:1px 5px; border-radius:3px; font-family:monospace; }
.t-std  { background:#d1fae5; color:#065f46; font-size:8px; font-weight:700; padding:1px 5px; border-radius:2px; }
.t-cust { background:#ede9fe; color:#7c3aed; font-size:8px; font-weight:700; padding:1px 5px; border-radius:2px; }

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
<div class="hdr">
  <div class="hdr-l">
    <div class="brand">VFRB Enterprise</div>
    <div class="sub">
      Tailor Centre VFRB Manila · #31 San Guillermo St., Bayanan, Muntinlupa City 1772<br>
      📞 0921 791 6259 · vfrb.enterprise@gmail.com
    </div>
  </div>
  <div class="hdr-r">
    <div class="doc">Production Receipt</div>
    <div class="doc-num">ORD-{{ str_pad($order['order_id'] ?? 0, 4, '0', STR_PAD_LEFT) }}</div>
    <div style="font-size:9px;color:#6b7280;margin-top:4px;">Printed: {{ $printedAt }}</div>
    @if(!empty($order['or_number']))
      <div style="font-size:9px;color:#6b7280;">OR# {{ $order['or_number'] }}</div>
    @endif
  </div>
</div>

{{-- PIPELINE — 7 stages --}}
@php
  // FIX: 7-stage pipeline (added segregation + pressing)
  $stages  = ['pending','confirmed','pattern','segregation','cutting','sewing','qc','pressing','packing','completed'];
  $labels  = ['Pending','Confirmed','Pattern','Segregation','Cutting','Sewing','QC','Pressing','Packing','Done'];
  $current = $order['status'] ?? 'pending';
  $curIdx  = array_search($current, $stages);
  if ($curIdx === false) $curIdx = -1;
@endphp
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

{{-- ORDER DETAILS --}}
<div class="sec">Order Information</div>
<table class="info">
  <tr>
    <td class="lbl">Customer</td>
    <td class="val">{{ $order['customer_name'] ?? '—' }}</td>
    <td class="lbl">Design</td>
    <td class="val">{{ $order['design_name'] ?? '—' }}</td>
  </tr>
  <tr>
    <td class="lbl">Email</td>
    <td class="val">{{ $order['customer_email'] ?? '—' }}</td>
    <td class="lbl">Category</td>
    <td class="val">{{ $order['category'] ?? '—' }}</td>
  </tr>
  <tr>
    <td class="lbl">Organization</td>
    <td class="val">{{ $order['customer_org'] ?: '(Individual)' }}</td>
    <td class="lbl">Quantity</td>
    <td class="val">{{ $order['quantity_ordered'] ?? '—' }} pieces</td>
  </tr>
  <tr>
    <td class="lbl">Order Date</td>
    <td class="val">
      {{ !empty($order['created_at']) ? \Carbon\Carbon::parse($order['created_at'])->format('F d, Y') : '—' }}
    </td>
    <td class="lbl">Color</td>
    <td class="val">{{ $order['color'] ?? '—' }}</td>
  </tr>
  <tr>
    <td class="lbl">Status</td>
    <td class="val"><span class="badge s-{{ $current }}">{{ ucfirst($current) }}</span></td>
    <td class="lbl">Order Type</td>
    <td class="val" style="text-transform:capitalize;">{{ str_replace('_',' ',$order['order_type'] ?? '—') }}</td>
  </tr>
  <tr>
    <td class="lbl">Sizing</td>
    <td class="val" colspan="3">
      @if(($order['sizing_type'] ?? '') === 'custom')
        Custom · Chest: {{ $order['chest'] ?? '?' }} cm
        @if(!empty($order['scale_ratio']))
          {{-- FIX: was "TESDA NC II M-baseline" --}}
          · Scale Ratio: {{ number_format($order['scale_ratio'], 4) }}× (VFRB Proprietary M-baseline = 96 cm)
        @endif
      @else
        Standard · Size: {{ $order['size_label'] ?? '—' }}
      @endif
    </td>
  </tr>
  @if(!empty($order['client_design_notes']))
  <tr>
    <td class="lbl">Design Notes</td>
    <td class="val" colspan="3">{{ $order['client_design_notes'] }}</td>
  </tr>
  @endif
  @if(!empty($order['target_delivery_date']))
  <tr>
    <td class="lbl">Target Delivery</td>
    <td class="val" colspan="3">{{ \Carbon\Carbon::parse($order['target_delivery_date'])->format('F d, Y') }}</td>
  </tr>
  @endif
</table>

{{-- BOM TABLE --}}
@if(!empty($order['recommendations']) && count($order['recommendations']) > 0)
<div class="sec">Bill of Materials — AI-Computed (SAP CS12 Equivalent)</div>
<table class="bom">
  <thead>
    <tr>
      <th style="width:33%">Material</th>
      <th style="width:14%">Category</th>
      <th style="width:13%">Est. Qty</th>
      <th style="width:8%">Unit</th>
      <th style="width:13%">Track</th>
      <th style="width:19%">Formula ID</th>
    </tr>
  </thead>
  <tbody>
    @foreach($order['recommendations'] as $r)
    <tr>
      <td style="font-weight:500;">{{ $r['material_name'] ?? '—' }}</td>
      <td>{{ $r['category'] ?? '—' }}</td>
      <td class="t-qty">{{ number_format((float)($r['estimated_qty'] ?? 0), 4) }}</td>
      <td>{{ $r['unit'] ?? '—' }}</td>
      <td>
        <span class="{{ ($r['computation_method'] ?? 'standard') === 'custom' ? 't-cust' : 't-std' }}">
          {{ ucfirst($r['computation_method'] ?? 'standard') }}
        </span>
      </td>
      <td><span class="t-fid">F-{{ str_pad($r['formula_id'] ?? '?', 3, '0', STR_PAD_LEFT) }}</span></td>
    </tr>
    @endforeach
  </tbody>
</table>

<div class="ai">
  <div class="ai-title">🤖 AI BOM Audit Trail — Deterministic Rule-Based Engine</div>
  @php
    $firstRec = $order['recommendations'][0] ?? [];
    $method   = $firstRec['computation_method'] ?? 'standard';
    $ratio    = $firstRec['scale_ratio'] ?? null;
  @endphp
  <div class="ai-row">
    <strong>Computation:</strong>
    @if($method === 'custom')
      {{-- FIX: was "Custom sizing (TESDA NC II)" --}}
      Custom sizing (VFRB Proprietary Parametric Formula) — client chest ÷ 96.0 cm = scale × M-size qty × quantity_ordered
      @if($ratio) · Scale = <strong>{{ number_format((float)$ratio, 4) }}×</strong> @endif
    @else
      Standard — qty_per_piece (from material_formulas) × quantity_ordered (SAP CS12 BOM explosion)
    @endif
  </div>
  <div class="ai-row" style="margin-top:4px;">
    Every row is traceable to a <strong>formula_id</strong> in the material_formulas table.
    Google Gemini Flash is used for plain-language explanation only — it never generates quantities.
    Same input → same output. Every time.
  </div>
</div>

@elseif(($order['order_type'] ?? '') === 'subcontract')
<div class="ai" style="border-left-color:#7c3aed;background:#faf5ff;margin-top:14px;">
  <div class="ai-title" style="color:#7c3aed;">🏭 Subcontract Order — Materials supplied by partner</div>
  <div class="ai-row">VFRB Enterprise provides sewing, pressing, and packing only. Materials are partner-supplied.</div>
</div>
@endif

{{-- PAYMENT --}}
@if(!empty($order['transaction']))
<div class="sec">Payment Summary (SAP F-28)</div>
<div class="pay">
  <div class="pay-l">
    <table class="info">
      <tr><td class="lbl">Payment Method</td><td class="val">{{ ucwords(str_replace('_',' ',$order['transaction']['payment_method'] ?? '—')) }}</td></tr>
      <tr><td class="lbl">Payment Terms</td><td class="val">{{ ucwords(str_replace('_',' ',$order['transaction']['payment_terms'] ?? '—')) }}</td></tr>
      <tr><td class="lbl">Payment Date</td><td class="val">{{ !empty($order['transaction']['payment_date']) ? \Carbon\Carbon::parse($order['transaction']['payment_date'])->format('M d, Y') : 'Pending' }}</td></tr>
      <tr><td class="lbl">OR Number</td><td class="val">{{ $order['transaction']['or_number'] ?? '—' }}</td></tr>
    </table>
  </div>
  <div class="pay-r">
    <div class="amount-box">
      <div class="amount-row"><span class="amount-lbl">Amount Total</span><span class="amount-val">₱{{ number_format($order['transaction']['amount_total'] ?? 0, 2) }}</span></div>
      <div class="amount-row"><span class="amount-lbl">Amount Paid</span><span class="amount-val">₱{{ number_format($order['transaction']['amount_paid'] ?? 0, 2) }}</span></div>
      <div class="amount-total-row"><span class="amount-total-lbl">Balance Due</span><span class="amount-total-val">₱{{ number_format($order['transaction']['balance_due'] ?? 0, 2) }}</span></div>
    </div>
  </div>
</div>
@endif

{{-- FOOTER --}}
<div class="foot">
  <div class="foot-l">
    <strong>VFRB Enterprise</strong> — AI-Enabled Sales & Inventory Management System<br>
    CCC BSIT Capstone 2026 · Deterministic BOM Engine · RA 10173 Data Privacy Compliant<br>
    Adviser: Rowan N. Elomina, PhD · Panel Chair: Dr. Regina G. Almonte
  </div>
  <div class="foot-r">
    Printed: {{ $printedAt }}<br>
    ORD-{{ str_pad($order['order_id'] ?? 0, 4, '0', STR_PAD_LEFT) }}<br>
    <span style="color:#028090;font-weight:700;">VFRB Enterprise</span>
  </div>
</div>
</body>
</html>