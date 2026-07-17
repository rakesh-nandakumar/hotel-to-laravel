@php
    $hotelName = \App\Services\Settings::str('hotel.name', 'Mount View Hotel');
    $hotelAddress = \App\Services\Settings::str('hotel.address', '');
    $hotelPhone = \App\Services\Settings::str('hotel.phone', '');
    $hotelEmail = \App\Services\Settings::str('hotel.email', '');
    $hotelTaxNo = \App\Services\Settings::str('hotel.tax_reg_no', '');
    $contact = implode('  |  ', array_filter([$hotelPhone, $hotelEmail]));
@endphp
<div class="center brand-name">{{ $hotelName }}</div>
<div class="center brand-meta">
    @if($hotelAddress)<div>{{ $hotelAddress }}</div>@endif
    @if($contact)<div>{{ $contact }}</div>@endif
    @if($hotelTaxNo)<div>Tax Reg: {{ $hotelTaxNo }}</div>@endif
</div>
@if($format === 'a4')
    <hr class="thick-hr">
@else
    <hr class="hr">
@endif
