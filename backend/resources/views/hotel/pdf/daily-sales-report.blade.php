@extends('hotel.pdf.layout')

@section('content')
@php
    $title = 'DAILY SALES REPORT';
    $formatLkr = fn($cents): string => number_format($cents / 100, 2);
    $signed = fn($cents): string => ($cents >= 0 ? '' : '-') . number_format(abs($cents) / 100, 2);
@endphp

<x-pdf-row bold :left="$title" :right="'Session #'.$session->id" />
<x-pdf-row :left="'Till: '.$session->till->name" />
<x-pdf-row :left="'Period: '.$session->opened_at->format('d/m/Y H:i').' - '.$session->closed_at->format('H:i')" />
<hr class="hr">

<x-pdf-row bold left="OPENING BALANCE" :right="$formatLkr($report['opening_balance'])" />
<hr class="hr">

<x-pdf-row bold left="CASH COLLECTED" />
@if($report['collections'])
    @foreach($report['collections'] as $line)
        <x-pdf-row :left="$line['label'].' ('.$line['count'].')'" :right="$formatLkr($line['amount'])" />
    @endforeach
@else
    <x-pdf-row left="No cash collected" right="0.00" />
@endif
<hr class="hr">
<x-pdf-row bold left="TOTAL COLLECTED" :right="$formatLkr($report['collections_total'])" />
<hr class="hr">

<x-pdf-row bold left="CASH PAID OUT" />
<x-pdf-row :left="'Refunds ('.$report['refunds']['count'].')'" :right="$signed($report['refunds']['amount'])" />
<x-pdf-row :left="'Manual Cash In ('.$report['manual_cash_in']['count'].')'" :right="$formatLkr($report['manual_cash_in']['amount'])" />
@if($report['manual_cash_out'])
    @foreach($report['manual_cash_out'] as $line)
        <x-pdf-row :left="$line['label'].' ('.$line['count'].')'" :right="$signed($line['amount'])" />
    @endforeach
@endif
@if($report['adjustments']['count'] > 0)
    <x-pdf-row :left="'Adjustments ('.$report['adjustments']['count'].')'" :right="$signed($report['adjustments']['amount'])" />
@endif
<hr class="hr">
<x-pdf-row bold left="NET CHANGE" :right="$signed($report['net_change'])" />
<hr class="hr">

<x-pdf-row bold left="CLOSING SUMMARY" />
<x-pdf-row :left="'Expected Balance'" :right="$formatLkr($report['expected_balance'])" />
<x-pdf-row :left="'Counted Cash'" :right="$formatLkr($session->closing_cash)" />
<x-pdf-row bold :left="'Variance'" :right="$signed($session->variance)" />
<hr class="hr">

<x-pdf-row :left="'Total Movements: '.$report['movement_count']" />
<x-pdf-row :left="'Closed at: '.$session->closed_at->format('d/m/Y H:i:s')" />
@endsection

@php($footerExtra = 'Generated on '.now()->format('d/m/Y H:i:s'))
@php($poweredBy = true)
