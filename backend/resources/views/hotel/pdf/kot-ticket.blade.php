@extends('hotel.pdf.layout')

@section('content')
@php
    $customerLine = $order->type->code === \App\Support\Lookups\OrderType::ROOM_GUEST
        ? 'Room '.($order->room->number ?? '')
        : ($order->customer_name ?: 'Walk-in');
    $visibleItems = $order->items->where('voided', false);
@endphp

<div class="center bold" style="font-size:12px">KOT — Order #{{ $order->id }}</div>
@if($order->type->code === \App\Support\Lookups\OrderType::WALKIN)
    <div class="center bold" style="font-size:9px">
        {{ $order->diningMode?->code === \App\Support\Lookups\DiningMode::TAKEAWAY ? '*** TAKEAWAY ***' : 'DINE-IN' }}
    </div>
@endif
<div class="center" style="font-size:9px">{{ $customerLine }}</div>
<div class="center" style="font-size:9px">{{ $order->created_at->format('H:i:s') }}</div>
<hr class="hr">
@foreach($visibleItems as $item)
    <div class="bold" style="font-size:11px">{{ $item->qty }} × {{ $item->name }}</div>
    @if($item->notes)
        <div style="font-size:9px">&nbsp;&nbsp;&nbsp;→ {{ $item->notes }}</div>
    @endif
@endforeach
@if($order->notes)
    <hr class="hr">
    <div style="font-size:9px">Note: {{ $order->notes }}</div>
@endif
@endsection
