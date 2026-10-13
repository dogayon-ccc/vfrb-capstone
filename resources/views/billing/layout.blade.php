{{-- Shared shell for billing documents. $mode = 'pdf' (dompdf, A4) or 'html' (phone-first, prints on A4).
     dompdf has no flexbox/grid, so layout is tables; the html block below only restacks them on narrow screens. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
@if($mode === 'html')<meta name="viewport" content="width=device-width, initial-scale=1"/>@endif
<title>@yield('title')</title>
<style>
@page { margin: 0; }
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'DejaVu Sans', Arial, sans-serif; font-size:11px; color:#1a1a2e; background:#fff; line-height:1.45; }
.page { padding:34px 36px; } /* dompdf ignores @page margins and box-sizing: pad, never width:100% */
.hdr { width:100%; border-collapse:collapse; border-bottom:3px solid #028090; margin-bottom:16px; }
.hdr td { vertical-align:top; padding-bottom:12px; }
.brand { font-size:20px; font-weight:700; color:#028090; }
.sub { font-size:9px; color:#6b7280; margin-top:3px; }
.doc { font-size:16px; font-weight:700; text-align:right; }
.doc-num { font-size:13px; font-weight:700; color:#028090; text-align:right; margin-top:3px; }
.note { font-size:9px; font-weight:700; color:#b45309; text-align:right; margin-top:3px; text-transform:uppercase; letter-spacing:.04em; }
.meta { font-size:9px; color:#6b7280; text-align:right; margin-top:3px; }
.sec { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:#028090;
       border-bottom:1.5px solid #e5e7eb; padding-bottom:4px; margin:16px 0 8px; }
.kv { width:100%; border-collapse:collapse; }
.kv td { padding:4px 6px; font-size:10px; vertical-align:top; }
.kv .k { color:#6b7280; font-weight:700; width:32%; } /* DejaVu has no 600 weight; 600 falls back to serif */
.lines { width:100%; border-collapse:collapse; margin-top:4px; }
.lines th { background:#f0fafa; color:#028090; font-size:8.5px; text-transform:uppercase; letter-spacing:.06em;
            padding:7px 8px; border-bottom:2px solid #028090; text-align:left; }
.lines th.num { text-align:right; }
.lines td { padding:7px 8px; border-bottom:1px solid #f0f0f0; font-size:10px; }
.num { text-align:right; white-space:nowrap; }
.totals { width:55%; margin-left:45%; border-collapse:collapse; margin-top:10px; }
.totals td { padding:4px 8px; font-size:10.5px; }
.totals .grand td { border-top:2px solid #028090; font-size:13px; font-weight:700; color:#028090; padding-top:7px; }
.big { font-size:22px; font-weight:700; color:#028090; }
.stamp { display:inline-block; padding:3px 10px; border:2px solid; border-radius:3px; font-size:11px; font-weight:700; letter-spacing:.1em; }
.stamp-paid { color:#047857; border-color:#047857; }
.stamp-void { color:#b91c1c; border-color:#b91c1c; }
.stamp-issued { color:#b45309; border-color:#b45309; }
.terms { font-size:9px; color:#374151; margin-top:14px; padding:8px 10px; background:#f9fafb; border-left:3px solid #028090; }
.foot { margin-top:22px; padding-top:10px; border-top:1px solid #e5e7eb; font-size:8.5px; color:#9ca3af; text-align:center; }
@if($mode === 'html')
body { font-size:15px; background:#f3f4f6; }
.page { max-width:820px; margin:0 auto; background:#fff; padding:20px 16px 28px; }
.brand { font-size:22px; } .doc { font-size:18px; } .doc-num { font-size:15px; }
.sub, .meta, .note, .sec, .foot, .terms { font-size:12px; }
.kv td, .lines td, .totals td { font-size:14px; }
.lines th { font-size:11px; }
.totals .grand td { font-size:17px; }
.big { font-size:28px; }
@media (max-width: 600px) {
  .hdr td { display:block; width:100%; }
  .doc, .doc-num, .note, .meta { text-align:left; }
  .kv td { display:block; width:100%; padding:2px 0; }
  .kv .k { width:100%; padding-top:8px; }
  .totals { width:100%; margin-left:0; }
  .lines .hide-sm { display:none; }
}
@media print {
  @page { size: A4; margin: 14mm; }
  body { background:#fff; font-size:11px; }
  .page { max-width:none; padding:0; }
}
@endif
</style>
</head>
<body>
<div class="page">
  <table class="hdr"><tr>
    <td>
      <div class="brand">{{ $company['name'] }}</div>
      <div class="sub">
        {{ $company['address'] }}
        @if($company['phone'])<br>{{ $company['phone'] }}@endif
        @if($company['email']) · {{ $company['email'] }}@endif
        @if($labels['company_tin'])<br>TIN {{ $labels['company_tin'] }}@endif
      </div>
    </td>
    <td>@yield('heading')</td>
  </tr></table>

  @yield('body')

  @if($labels['terms'])
    <div class="terms">{{ $labels['terms'] }}</div>
  @endif

  <div class="foot">
    @yield('footnote')
    Printed {{ $printedAt }} · System-generated document.
  </div>
</div>
</body>
</html>
