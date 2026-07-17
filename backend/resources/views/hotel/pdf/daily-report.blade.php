@extends('hotel.pdf.layout')

@section('content')
<div class="center bold" style="font-size:14px">{{ $meta['title'] }}</div>
<div class="center" style="font-size:10px">{{ \Carbon\CarbonImmutable::parse($data['date'])->translatedFormat('l, F j, Y') }}</div>
@if(!empty($meta['run_by']))
    <div class="center muted">Run by {{ $meta['run_by'] }}</div>
@endif

<div class="section-title">Occupancy</div>
<table class="grid">
    <tr><th>Total rooms</th><th>Occupied</th><th>Occupancy %</th></tr>
    <tr>
        <td>{{ $data['occupancy']['total_rooms'] }}</td>
        <td>{{ $data['occupancy']['occupied_rooms'] }}</td>
        <td>{{ $data['occupancy']['pct'] }}%</td>
    </tr>
</table>

<div class="section-title">Revenue by source</div>
<table class="grid">
    <tr><th>Source</th><th class="right">Amount (LKR)</th></tr>
    @forelse($data['revenue_by_source'] as $source => $amount)
        <tr><td>{{ $source }}</td><td class="right">{{ \App\Support\Money::format($amount) }}</td></tr>
    @empty
        <tr><td>—</td><td class="right">0.00</td></tr>
    @endforelse
</table>
<x-pdf-row left="Walk-in POS revenue" :right="\App\Support\Money::format($data['walkin_pos_revenue'])" />
<x-pdf-row bold left="TOTAL CHARGES POSTED" :right="\App\Support\Money::format($data['total_charges_posted'])" />

<div class="section-title">Payments by method</div>
<table class="grid">
    <tr><th>Method</th><th class="right">Amount (LKR)</th></tr>
    @forelse($data['payments']['by_method'] as $method => $amount)
        <tr><td>{{ $method }}</td><td class="right">{{ \App\Support\Money::format($amount) }}</td></tr>
    @empty
        <tr><td>—</td><td class="right">0.00</td></tr>
    @endforelse
</table>
<x-pdf-row left="Collected" :right="\App\Support\Money::format($data['payments']['collected'])" />
<x-pdf-row left="Refunded" :right="\App\Support\Money::format($data['payments']['refunded'])" />
<x-pdf-row bold left="NET COLLECTED" :right="\App\Support\Money::format($data['payments']['net'])" />
<x-pdf-row left="Cash collected" :right="\App\Support\Money::format($data['cash_collected'])" />

@if(count($data['pos']['best_sellers']))
    <div class="section-title">POS — best sellers</div>
    <table class="grid">
        <tr><th>Item</th><th>Qty</th><th class="right">Amount (LKR)</th></tr>
        @foreach(array_slice($data['pos']['best_sellers'], 0, 10) as $item)
            <tr><td>{{ $item['name'] }}</td><td>{{ $item['qty'] }}</td><td class="right">{{ \App\Support\Money::format($item['amount']) }}</td></tr>
        @endforeach
    </table>
@endif

@if(count($data['shifts']))
    <div class="section-title">Shift / cash-drawer reconciliation</div>
    <table class="grid">
        <tr><th>Staff</th><th>Opening</th><th>Expected</th><th>Counted</th><th>Variance</th></tr>
        @foreach($data['shifts'] as $shift)
            <tr>
                <td>{{ $shift['staff'] }}</td>
                <td>{{ \App\Support\Money::format($shift['opening_cash']) }}</td>
                <td>{{ $shift['expected_cash'] !== null ? \App\Support\Money::format($shift['expected_cash']) : '—' }}</td>
                <td>{{ $shift['closing_cash'] !== null ? \App\Support\Money::format($shift['closing_cash']) : '—' }}</td>
                <td>{{ $shift['variance'] !== null ? \App\Support\Money::format($shift['variance']) : '—' }}</td>
            </tr>
        @endforeach
    </table>
@endif
@endsection
