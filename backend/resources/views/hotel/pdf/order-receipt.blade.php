@extends('hotel.pdf.layout')

@section('content')
@php
    $modeLabel = $order->type->code === \App\Support\Lookups\OrderType::WALKIN
        ? ($order->diningMode?->code === \App\Support\Lookups\DiningMode::TAKEAWAY ? 'TAKEAWAY' : 'DINE-IN')
        : '';
    $customerLine = $order->type->code === \App\Support\Lookups\OrderType::ROOM_GUEST
        ? 'Room '.($order->room->number ?? '').' (charged to folio)'
        : ($order->customer_name ?: 'Walk-in');
    $visibleItems = $order->items->where('voided', false);
    $paid = $order->payments->where('kind.code', '!=', \App\Support\Lookups\PaymentKind::REFUND)->sum('amount')
        - $order->payments->where('kind.code', '=', \App\Support\Lookups\PaymentKind::REFUND)->sum('amount');
@endphp

<x-pdf-row bold :left="'Receipt — Order #'.$order->id" :right="$modeLabel" />
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
    <x-pdf-row :left="'Discount'.($order->discount_reason ? ' ('.$order->discount_reason.')' : '')" :right="'-'.\App\Support\Money::format($order->discount)" />
@endif
@if($order->service_charge > 0)
    <x-pdf-row left="Service Charge" :right="\App\Support\Money::format($order->service_charge)" />
@endif
@if($order->vat > 0)
    <x-pdf-row left="VAT" :right="\App\Support\Money::format($order->vat)" />
@endif
<x-pdf-row bold left="TOTAL (LKR)" :right="\App\Support\Money::format($order->total)" />
@if($order->payments->isNotEmpty())
    <hr class="hr">
    @php
        $hasChange = $order->payments->contains(fn ($p) => $p->reason === 'Change returned to guest');
    @endphp
    @if($hasChange)
        <x-pdf-row left="TENDERED" :right="\App\Support\Money::format($paid + $order->payments->where('reason', 'Change returned to guest')->sum('amount'))" />
    @endif
    @foreach($order->payments as $payment)
        @php
            $isChange = $payment->reason === 'Change returned to guest';
            $label = $isChange ? 'Change' : ($payment->kind->code === \App\Support\Lookups\PaymentKind::REFUND ? 'Refund' : null);
        @endphp
        <x-pdf-row
            :left="($label ? $label.' — ' : '').$payment->method->code.($payment->reference ? ' ('.$payment->reference.')' : '')"
            :right="($payment->kind->code === \App\Support\Lookups\PaymentKind::REFUND ? '-' : '').\App\Support\Money::format($payment->amount)"
        />
    @endforeach
@endif
@if($paid < $order->total)
    <x-pdf-row bold left="BALANCE DUE" :right="\App\Support\Money::format($order->total - $paid)" />
@endif
@endsection

@php($footerExtra = 'Thank you — please come again!')
@php($poweredBy = true)
