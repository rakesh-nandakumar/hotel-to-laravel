<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Venue Booking - {{ $booking->code }}</title>
<style>
    @page {
        size: A4;
        margin: 12mm;
    }
    body {
        font-family: 'Times New Roman', Times, serif;
        color: #000;
        font-size: 12px;
        line-height: 1.6;
    }
    .header {
        text-align: center;
        border-bottom: 3px solid #000;
        padding-bottom: 15px;
        margin-bottom: 25px;
    }
    .header h1 {
        font-size: 22px;
        margin: 0;
        text-transform: uppercase;
        letter-spacing: 2px;
        font-weight: bold;
    }
    .header p {
        font-size: 14px;
        margin: 5px 0 0;
        font-style: italic;
    }
    .section {
        margin-bottom: 25px;
        border: 1px solid #000;
        padding: 20px;
    }
    .section-title {
        font-size: 14px;
        font-weight: bold;
        text-transform: uppercase;
        border-bottom: 2px solid #000;
        padding-bottom: 8px;
        margin-bottom: 15px;
        letter-spacing: 1px;
    }
    .info-row {
        display: flex;
        padding: 8px 0;
        border-bottom: 1px dashed #ccc;
    }
    .info-label {
        width: 200px;
        font-weight: bold;
        flex-shrink: 0;
    }
    .info-value {
        flex: 1;
    }
    .status-box {
        text-align: center;
        padding: 15px;
        border: 2px solid #000;
        margin: 20px 0;
        font-weight: bold;
        font-size: 14px;
        text-transform: uppercase;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
    }
    table th {
        border: 1px solid #000;
        background: #f0f0f0;
        padding: 10px;
        text-align: left;
        font-weight: bold;
        text-transform: uppercase;
        font-size: 11px;
    }
    table td {
        border: 1px solid #000;
        padding: 10px;
    }
    .total-row {
        background: #000;
        color: #fff;
        font-weight: bold;
    }
    .footer {
        text-align: center;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 2px solid #000;
        font-size: 10px;
    }
</style>
</head>
<body>
<div style="max-width: 700px; margin: 0 auto; padding: 10px;">
    <!-- Header -->
    <div class="header">
        <h1>Venue Booking Confirmation</h1>
        <p>Booking Reference: {{ $booking->code }}</p>
    </div>

    <!-- Client Information -->
    <div class="section">
        <div class="section-title">Client Information</div>
        <div class="info-row">
            <div class="info-label">Client Name:</div>
            <div class="info-value">{{ $booking->client_name }}</div>
        </div>
        @if($booking->client_phone)
        <div class="info-row">
            <div class="info-label">Telephone:</div>
            <div class="info-value">{{ $booking->client_phone }}</div>
        </div>
        @endif
        @if($booking->client_email)
        <div class="info-row">
            <div class="info-label">Email Address:</div>
            <div class="info-value">{{ $booking->client_email }}</div>
        </div>
        @endif
    </div>

    <!-- Event Information -->
    <div class="section">
        <div class="section-title">Event Information</div>
        <div class="info-row">
            <div class="info-label">Event Type:</div>
            <div class="info-value">{{ $booking->event_type ?? '—' }}</div>
        </div>
        <div class="info-row">
            <div class="info-label">Venue:</div>
            <div class="info-value">
                @if($booking->venue_ids && is_array($booking->venue_ids) && count($booking->venue_ids) > 1)
                    {{ \App\Models\Hotel\Venue::query()->whereIn('id', $booking->venue_ids)->pluck('name')->join(' + ') }}
                @else
                    {{ $booking->venue->name }}
                @endif
            </div>
        </div>
        <div class="info-row">
            <div class="info-label">Event Date:</div>
            <div class="info-value">{{ $booking->date->format('l, jS F Y') }}</div>
        </div>
        @if($booking->start_time && $booking->end_time)
        <div class="info-row">
            <div class="info-label">Event Time:</div>
            <div class="info-value">{{ $booking->start_time }} - {{ $booking->end_time }}</div>
        </div>
        @endif
        <div class="info-row">
            <div class="info-label">Number of Guests:</div>
            <div class="info-value">{{ $booking->guest_count }} / {{ $booking->venue->max_capacity }}</div>
        </div>
        <div class="info-row">
            <div class="info-label">Catering Arrangement:</div>
            <div class="info-value">{{ $booking->catering_by_hotel ? 'Hotel provides catering' : "Client's own chefs (rental only)" }}</div>
        </div>
        @if($booking->notes)
        <div class="info-row">
            <div class="info-label">Special Requirements:</div>
            <div class="info-value">{{ $booking->notes }}</div>
        </div>
        @endif
    </div>

    <!-- Booking Status -->
    <div class="status-box">
        Booking Status: {{ $booking->status->name }}
    </div>

    <!-- Package Details -->
    @if($booking->package_type)
    <div class="section">
        <div class="section-title">Package Details</div>
        <div class="info-row">
            <div class="info-label">Package Type:</div>
            <div class="info-value">{{ str_replace('_', ' ', $booking->package_type) }}</div>
        </div>
        @if($booking->hall_charge_used)
        <div class="info-row">
            <div class="info-label">Hall Charge:</div>
            <div class="info-value">{{ number_format($booking->hall_charge_used / 100, 2) }} LKR</div>
        </div>
        @endif
        @if($booking->per_plate_price)
        <div class="info-row">
            <div class="info-label">Per Plate Price:</div>
            <div class="info-value">{{ number_format($booking->per_plate_price / 100, 2) }} LKR</div>
        </div>
        @endif
        @if($booking->service_charge_pct)
        <div class="info-row">
            <div class="info-label">Service Charge:</div>
            <div class="info-value">{{ $booking->service_charge_pct }}%</div>
        </div>
        @endif
        @if($booking->dj_required !== null)
        <div class="info-row">
            <div class="info-label">DJ Service:</div>
            <div class="info-value">{{ $booking->dj_required ? 'Included' : 'Not Required' }}</div>
        </div>
        @endif
        @if($booking->byod_selected !== null)
        <div class="info-row">
            <div class="info-label">BYOD Policy:</div>
            <div class="info-value">{{ $booking->byod_selected ? 'Allowed' : 'Not Allowed' }}</div>
        </div>
        @endif
    </div>
    @endif

    <!-- Additional Charges -->
    @if(isset($booking->extraCharges) && $booking->extraCharges->count() > 0)
    <div class="section">
        <div class="section-title">Additional Charges</div>
        <table>
            <thead>
                <tr>
                    <th width="60%">Description</th>
                    <th width="40%" style="text-align: right;">Amount (LKR)</th>
                </tr>
            </thead>
            <tbody>
                @foreach($booking->extraCharges as $extra)
                <tr>
                    <td>{{ $extra->description }}</td>
                    <td style="text-align: right;">{{ number_format($extra->amount / 100, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <!-- Payment Summary -->
    @if($booking->advance_payment > 0 || $booking->profit_margin > 0)
    <div class="section">
        <div class="section-title">Payment Summary</div>
        @if($booking->advance_payment > 0)
        <div class="info-row">
            <div class="info-label">Advance Payment:</div>
            <div class="info-value">{{ number_format($booking->advance_payment / 100, 2) }} LKR</div>
        </div>
        @if($booking->advance_payment_method)
        <div class="info-row">
            <div class="info-label">Payment Method:</div>
            <div class="info-value">{{ ucfirst($booking->advance_payment_method) }}</div>
        </div>
        @endif
        @if($booking->advance_paid_at)
        <div class="info-row">
            <div class="info-label">Payment Date:</div>
            <div class="info-value">{{ $booking->advance_paid_at->format('d F Y, H:i') }}</div>
        </div>
        @endif
        @endif
        @if($booking->profit_margin > 0)
        <div class="info-row">
            <div class="info-label">Profit Margin:</div>
            <div class="info-value">{{ number_format($booking->profit_margin / 100, 2) }} LKR</div>
        </div>
        @endif
    </div>
    @endif

    <!-- Footer -->
    <div class="footer">
        <p>This document was generated on {{ now()->format('d F Y \a\t H:i') }}</p>
        <p>Mountview Hotel Management System • Venue Booking Confirmation</p>
    </div>
</div>
</body>
</html>
