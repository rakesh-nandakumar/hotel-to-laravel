@extends('hotel.pdf.layout')

@section('content')
<div class="center bold" style="font-size:14px">POS SALES REPORT</div>
<div class="center" style="font-size:10px">{{ $data['from'] }} → {{ $data['to'] }}</div>

<div class="section-title">Sales by category</div>
<table class="grid">
    <tr><th>Category</th><th class="right">Amount (LKR)</th></tr>
    @forelse($data['by_category'] as $category => $amount)
        <tr><td>{{ $category }}</td><td class="right">{{ \App\Support\Money::format($amount) }}</td></tr>
    @empty
        <tr><td>—</td><td class="right">0.00</td></tr>
    @endforelse
</table>

<div class="section-title">Best sellers</div>
<table class="grid">
    <tr><th>Item</th><th>Qty</th><th class="right">Amount (LKR)</th></tr>
    @foreach($data['best_sellers'] as $item)
        <tr><td>{{ $item['name'] }}</td><td>{{ $item['qty'] }}</td><td class="right">{{ \App\Support\Money::format($item['amount']) }}</td></tr>
    @endforeach
</table>

<div class="section-title">Payment method breakdown</div>
<table class="grid">
    <tr><th>Method</th><th class="right">Amount (LKR)</th></tr>
    @forelse($data['payment_method_breakdown'] as $method => $amount)
        <tr><td>{{ $method }}</td><td class="right">{{ \App\Support\Money::format($amount) }}</td></tr>
    @empty
        <tr><td>—</td><td class="right">0.00</td></tr>
    @endforelse
</table>

<x-pdf-row bold left="TOTAL SALES (LKR)" :right="\App\Support\Money::format($data['total_sales'])" />
@endsection
