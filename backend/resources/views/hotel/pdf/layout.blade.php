<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $title ?? 'Document' }}</title>
<style>
    @page {
        margin: {{ $format === 'thermal' ? '10px 8px' : '40px' }};
        @if($format === 'thermal') size: 226px 1200px; @else size: A4; @endif
    }
    body { font-family: Helvetica, Arial, sans-serif; color: #000; font-size: {{ $format === 'thermal' ? '8px' : '10px' }}; }
    .center { text-align: center; }
    .right { text-align: right; }
    .bold { font-weight: bold; }
    .brand-name { color: #0f4c81; font-size: {{ $format === 'thermal' ? '11px' : '18px' }}; font-weight: bold; }
    .brand-meta { font-size: {{ $format === 'thermal' ? '7px' : '9px' }}; }
    .hr { border: none; border-top: 0.5px solid #999; margin: 4px 0; }
    .thick-hr { border: none; border-top: 2.5px solid #0f4c81; margin: 6px 0; }
    table.doc { width: 100%; border-collapse: collapse; }
    table.doc td { padding: 2px 0; vertical-align: top; }
    .section-title { color: #0f4c81; font-weight: bold; font-size: 11px; text-transform: uppercase; margin-top: 12px; margin-bottom: 4px; }
    table.grid { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    table.grid th { background: #f1f5f9; text-align: left; font-size: 9px; padding: 4px 3px; }
    table.grid th.right, table.grid td.right { text-align: right; }
    table.grid td { font-size: 9px; padding: 3px; border-bottom: 0.5px solid #eee; }
    .muted { color: #999; font-size: 7px; }
    .footer { margin-top: 14px; }
    .big-token { font-size: 46px; font-weight: bold; text-align: center; }
</style>
</head>
<body>
@include('hotel.pdf.partials.header', ['format' => $format])
@yield('content')
@include('hotel.pdf.partials.footer', ['format' => $format, 'extra' => $footerExtra ?? null, 'poweredBy' => $poweredBy ?? null])
</body>
</html>
