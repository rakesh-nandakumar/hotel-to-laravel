@extends('hotel.pdf.layout')

@section('content')
@php
    $bestDay = collect($data['days'])->sortByDesc('revenue')->first();
@endphp
<div class="center bold" style="font-size:14px">MONTHLY PERFORMANCE REPORT</div>
<div class="center" style="font-size:10px">{{ \Carbon\CarbonImmutable::parse($data['month'].'-01')->translatedFormat('F Y') }}</div>

<div class="section-title">Summary</div>
<x-pdf-row bold left="Total revenue" :right="\App\Support\Money::format($data['total_revenue'])" />
<x-pdf-row left="Average occupancy" :right="$data['avg_occupancy'].'%'" />
@if($bestDay)
    <x-pdf-row left="Best day" :right="$bestDay['date'].' — '.\App\Support\Money::format($bestDay['revenue'])" />
@endif

<div class="section-title">Daily breakdown</div>
<table class="grid">
    <tr><th>Date</th><th class="right">Revenue (LKR)</th><th class="right">Occupancy %</th></tr>
    @foreach($data['days'] as $day)
        <tr>
            <td>{{ $day['date'] }}</td>
            <td class="right">{{ \App\Support\Money::format($day['revenue']) }}</td>
            <td class="right">{{ $day['occupancy_pct'] }}%</td>
        </tr>
    @endforeach
</table>
@endsection
