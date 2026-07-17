@extends('hotel.pdf.layout')

@section('content')
@php
    $title = $folio->type->code === \App\Support\Lookups\FolioType::VENUE ? 'VENUE EVENT INVOICE' : 'GUEST STAY INVOICE';
@endphp

<x-pdf-row bold :left="$title" :right="$folio->invoice_no ?? 'PROFORMA'" />
@if($folio->reservation)
    <x-pdf-row :left="'Guest: '.$folio->reservation->guest->name" />
    @if($folio->reservation->guest->id_number)
        <x-pdf-row :left="'ID/Passport: '.$folio->reservation->guest->id_number" />
    @endif
    <x-pdf-row :left="'Booking: '.$folio->reservation->code" />
    <x-pdf-row :left="'Rooms: '.$folio->reservation->rooms->pluck('room.number')->implode(', ')" />
    <x-pdf-row :left="'Stay: '.$folio->reservation->check_in->format('Y-m-d').' → '.$folio->reservation->check_out->format('Y-m-d')" />
@endif
@if($folio->venueBooking)
    <x-pdf-row :left="'Venue: '.$folio->venueBooking->venue->name" />
    <x-pdf-row :left="'Client: '.$folio->venueBooking->client_name" />
    <x-pdf-row :left="'Event date: '.$folio->venueBooking->date->format('Y-m-d')" />
@endif
<hr class="hr">

@if($format === 'a4')
    <table class="grid">
        <tr><th style="width:15%">DATE</th><th style="width:60%">DESCRIPTION</th><th class="right" style="width:25%">AMOUNT (LKR)</th></tr>
        @foreach($folio->lines as $line)
            <tr>
                <td>{{ $line->created_at->format('d/m') }}</td>
                <td>{{ $line->description }}</td>
                <td class="right">{{ \App\Support\Money::format($line->amount) }}</td>
            </tr>
        @endforeach
    </table>
@else
    @foreach($folio->lines as $line)
        <x-pdf-row :left="$line->description" :right="\App\Support\Money::format($line->amount)" />
    @endforeach
@endif
<hr class="hr">
<x-pdf-row bold left="TOTAL (LKR)" :right="\App\Support\Money::format($totals['total'])" />

@foreach($folio->payments as $payment)
    @php
        $kindLabel = $payment->kind->code === \App\Support\Lookups\PaymentKind::REFUND ? 'Refund'
            : ($payment->kind->code === \App\Support\Lookups\PaymentKind::DEPOSIT ? 'Deposit' : 'Payment');
    @endphp
    <x-pdf-row
        :left="$kindLabel.' — '.$payment->method->code.($payment->reference ? ' ('.$payment->reference.')' : '').' '.$payment->created_at->format('d/m/Y')"
        :right="($payment->kind->code === \App\Support\Lookups\PaymentKind::REFUND ? '' : '-').\App\Support\Money::format($payment->amount)"
    />
@endforeach
<x-pdf-row bold :left="$totals['balance'] > 0 ? 'BALANCE DUE' : 'BALANCE'" :right="\App\Support\Money::format(abs($totals['balance']))" />
@endsection

@php($footerExtra = 'Settlement currency: LKR. Thank you for choosing us!')
