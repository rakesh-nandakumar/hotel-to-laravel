<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>KOT #{{ $order->id }}</title>
<style>
    @page { size: 226px 1400px; margin: 8px 8px; }
    body { font-family: Helvetica, Arial, sans-serif; color: #000; font-size: 9px; margin: 0; }
    .kot-banner { background: #000; color: #fff; text-align: center; font-size: 20px; font-weight: 900; letter-spacing: 6px; padding: 6px 0; }
    .order-no { text-align: center; font-size: 40px; font-weight: 900; line-height: 1.1; margin-top: 4px; }
    .center { text-align: center; }
    .label { text-align: center; font-size: 10px; font-weight: bold; margin-top: 2px; }
    .tag { display: inline-block; border: 2px solid #000; font-weight: 900; font-size: 9px; padding: 1px 6px; margin-top: 3px; }
    .sub { text-align: center; font-size: 9px; margin-top: 2px; }
    .hr { border: none; border-top: 2px solid #000; margin: 6px 0; }
    .thin { border: none; border-top: 0.5px dashed #666; margin: 4px 0; }
    .station { font-weight: 900; font-size: 11px; text-transform: uppercase; margin: 6px 0 2px; }
    .item { font-size: 13px; font-weight: 900; line-height: 1.25; margin: 4px 0 0; }
    .note { font-size: 9px; font-weight: bold; color: #7a4a00; margin-left: 8px; }
    .muted { color: #555; font-size: 8px; }
</style>
</head>
<body>
<div class="kot-banner">KOT</div>
<div class="order-no">#{{ $order->id }}</div>
@php
    $mode = $order->diningMode?->code ?? null;
    $isTakeaway = $order->type->code === \App\Support\Lookups\OrderType::WALKIN && $mode === \App\Support\Lookups\DiningMode::TAKEAWAY;
    $isDelivery = $order->type->code === \App\Support\Lookups\OrderType::DELIVERY;
@endphp
@if($order->dining_table)
    <div class="label">TABLE {{ $order->dining_table->table_no }}</div>
@elseif($order->type->code === \App\Support\Lookups\OrderType::ROOM_GUEST)
    <div class="label">ROOM {{ $order->room->number ?? '—' }}</div>
@else
    <div class="label">{{ $order->customer_name ?: 'WALK-IN' }}</div>
@endif
@if($isTakeaway)
    <div class="center"><span class="tag">TAKEAWAY</span></div>
@elseif($isDelivery)
    <div class="center"><span class="tag">DELIVERY</span></div>
@endif
<div class="sub">{{ $order->created_at->format('H:i') }} · {{ $order->created_at->format('d/m') }}</div>
<hr class="hr">
@php
    $items = $order->items->where('voided', false)->where('send_to_kot', true)->values();
    $grouped = $items->groupBy(fn ($i) => $i->menuItem?->category?->kitchenStation?->name ?? ($i->addOn ? 'Add-ons' : 'Kitchen'));
@endphp
@foreach($grouped as $station => $stationItems)
    <div class="station">◤ {{ strtoupper($station) }}</div>
    @foreach($stationItems as $item)
        <div class="item">{{ $item->qty }} × {{ $item->name }}</div>
        @if($item->notes)
            <div class="note">→ {{ $item->notes }}</div>
        @endif
    @endforeach
@endforeach
@if($order->notes)
    <hr class="thin">
    <div><b>ORDER NOTE:</b> {{ $order->notes }}</div>
@endif
<hr class="hr">
<div class="center muted">Serve promptly · {{ $order->created_at->format('H:i:s') }}</div>
</body>
</html>
