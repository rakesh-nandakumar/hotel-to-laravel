@extends('hotel.pdf.layout')

@section('content')
@php
    $modeLabel = $order->diningMode?->code === \App\Support\Lookups\DiningMode::TAKEAWAY ? 'TAKEAWAY' : 'DINE-IN';
    $customerLine = $order->type->code === \App\Support\Lookups\OrderType::ROOM_GUEST
        ? 'Room '.($order->room->number ?? '')
        : ($order->customer_name ?: 'Walk-in');
    $visibleItems = $order->items->where('voided', false);
    $paid = $order->payments->where('kind.code', '!=', \App\Support\Lookups\PaymentKind::REFUND)->sum('amount')
        - $order->payments->where('kind.code', '=', \App\Support\Lookups\PaymentKind::REFUND)->sum('amount');
@endphp

<x-pdf-row bold :left="'BILL — Order #'.$order->id" :right="$modeLabel" />
<x-pdf-row :left="$customerLine" />
<x-pdf-row :left="$order->created_at->format('d/m/Y, H:i:s')" />
<x-pdf-row :left="'Served by: '.$order->staff->name" />
<hr class="hr">
@foreach($visibleItems as $item)
    <x-pdf-row :left="$item->qty.' × '.$item->name" :right="\App\Support\Money::format($item->amount)" />
@endforeach
<hr class="hr">
<x-pdf-row left="Subtotal" :right="\App\Support\Money::format($order->subtotal)" />
@if($order->discount > 0)
    <x-pdf-row left="Discount" :right="'-'.\App\Support\Money::format($order->discount)" />
@endif
@if($order->service_charge > 0)
    <x-pdf-row left="Service Charge" :right="\App\Support\Money::format($order->service_charge)" />
@elseif($order->diningMode?->code === \App\Support\Lookups\DiningMode::TAKEAWAY)
    <x-pdf-row left="Service Charge" right="waived (takeaway)" />
@endif
@if($order->vat > 0)
    <x-pdf-row left="VAT" :right="\App\Support\Money::format($order->vat)" />
@endif
<x-pdf-row bold left="TOTAL (LKR)" :right="\App\Support\Money::format($order->total)" />
@if($paid >= $order->total)
    <x-pdf-row bold left="PAID ✓" :right="\App\Support\Money::format($paid)" />
@elseif($paid > 0)
    <x-pdf-row left="Paid so far" :right="\App\Support\Money::format($paid)" />
    <x-pdf-row bold left="BALANCE DUE AT COUNTER" :right="\App\Support\Money::format($order->total - $paid)" />
@else
    <x-pdf-row bold left="PAY AT COUNTER" :right="\App\Support\Money::format($order->total)" />
@endif

<div style="margin-top:14px" class="center">✂{{ str_repeat(' -', 30) }}</div>
<div style="margin-top:14px"></div>

<div class="center">COLLECTION TOKEN</div>
<div class="big-token">#{{ $order->id }}</div>
<div class="center">{{ $order->customer_name ?: 'Walk-in' }}</div>
<div class="center muted">{{ $order->created_at->format('H:i') }}</div>
<div style="margin-top:6px" class="center muted">Please present this number at the counter</div>
<div class="center muted">when your order is called / marked READY.</div>
@endsection
