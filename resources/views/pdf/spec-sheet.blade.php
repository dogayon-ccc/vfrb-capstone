{{-- resources/views/pdf/spec-sheet.blade.php · one-page design spec sheet from studio_config. Never carries prices (customer copy uses it too). --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'DejaVu Sans',Arial,sans-serif; font-size:10px; color:#1a1a2e; padding:26px; }
.hdr { display:table; width:100%; border-bottom:3px solid #028090; padding-bottom:12px; margin-bottom:12px; }
.hdr-l, .hdr-r { display:table-cell; vertical-align:top; }
.hdr-r { text-align:right; }
.brand { font-size:20px; font-weight:700; color:#028090; }
.sub { font-size:9px; color:#6b7280; margin-top:3px; }
.doc { font-size:14px; font-weight:700; }
.sec { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:#028090;
       border-bottom:1.5px solid #e5e7eb; padding-bottom:3px; margin:12px 0 6px; }
table { width:100%; border-collapse:collapse; }
td, th { padding:4px 6px; text-align:left; vertical-align:middle; }
th { font-size:8px; text-transform:uppercase; color:#6b7280; border-bottom:1px solid #e5e7eb; }
.grid td { border-bottom:1px solid #f1f5f9; }
.lbl { color:#6b7280; font-weight:700; width:90px; }
.sw { display:inline-block; width:14px; height:14px; border:1px solid #9ca3af; vertical-align:middle; margin-right:6px; }
.muted { color:#6b7280; font-size:8.5px; }
</style>
</head>
<body>
@php
  $sizeRows = collect($sizes);
  $std = $sizeRows->where('type', 'standard');
  $custom = $sizeRows->where('type', '!=', 'standard')->count();
@endphp

<div class="hdr">
  <div class="hdr-l">
    <div class="brand">VFRB Enterprise</div>
    <div class="sub">{{ $order->organization_name ?: $order->customer_name }}</div>
  </div>
  <div class="hdr-r">
    <div class="doc">Design Spec Sheet</div>
    <div class="sub">Order #{{ $order->order_id }} · {{ ucfirst($order->status) }} · {{ $printedAt }}</div>
  </div>
</div>

<table>
  <tr>
    <td class="lbl">Design</td><td>{{ ($cfg['name'] ?? '') ?: '—' }}</td>
    <td class="lbl">Category</td><td>{{ $cfg['category'] ?? '—' }}</td>
  </tr>
  <tr>
    <td class="lbl">Garment</td><td>{{ $cfg['garment'] ?? $cfg['garmentType'] ?? '—' }}</td>
    <td class="lbl">Fit / Sleeve</td><td>{{ implode(' · ', array_filter([ucfirst($cfg['fit'] ?? ''), $cfg['sleeve'] ?? null])) ?: '—' }}</td>
  </tr>
</table>

<div class="sec">Front / Back</div>
@if($preview)
  <div style="text-align:center;"><img src="{{ $preview }}" style="max-width:100%;max-height:300px;"></div>
@else
  <div class="muted">No design preview image was saved with this order.</div>
@endif

<div class="sec">Colours by zone</div>
<table class="grid">
  <tr><th>Zone</th><th>Colour</th><th>Pattern</th></tr>
  @foreach($zones as $zone => $hex)
  <tr>
    <td>{{ ucfirst($zone) }}</td>
    <td><span class="sw" style="background:{{ $hex }};"></span>{{ strtoupper($hex) }}</td>
    <td>{{ ucfirst($cfg['patterns'][$zone] ?? 'solid') }}</td>
  </tr>
  @endforeach
</table>

<div class="sec">Logos and text</div>
@if(count($layers))
<table class="grid">
  <tr><th>Face</th><th>Type</th><th>Content</th><th>Position (x, y)</th><th>Size (w × h)</th><th>Rotation</th></tr>
  @foreach($layers as $l)
  <tr>
    <td>{{ $l['face'] }}</td>
    <td>{{ $l['kind'] }}</td>
    <td>
      @if($l['img'])<img src="{{ $l['img'] }}" style="max-width:40px;max-height:40px;vertical-align:middle;">@endif
      @if($l['text'])“{{ $l['text'] }}”<div class="muted">{{ $l['font'] }}@if($l['fill']) · {{ $l['fill'] }}@endif</div>@endif
    </td>
    <td>{{ $l['x'] }}, {{ $l['y'] }}</td>
    <td>{{ $l['w'] }} × {{ $l['h'] }}</td>
    <td>{{ $l['angle'] }}°</td>
  </tr>
  @endforeach
</table>
<div class="muted" style="margin-top:4px;">Positions and sizes are in Design Studio canvas pixels; use the front/back image for visual placement.</div>
@else
  <div class="muted">No logos or text on this design.</div>
@endif

<div class="sec">Sizes</div>
<table class="grid">
  <tr>
    @forelse($std as $s)<td><b>{{ $s->size_label }}</b> · {{ $s->qty }} pcs</td>@empty<td class="muted">No standard size breakdown.</td>@endforelse
  </tr>
</table>
@if($custom)<div class="muted" style="margin-top:4px;">{{ $custom }} custom measurement record(s) on file.</div>@endif
<div style="margin-top:6px;"><b>Total:</b> {{ $order->quantity_ordered ?? 0 }} pcs · {{ ucfirst($order->sizing_type ?? 'standard') }} sizing</div>
</body>
</html>
