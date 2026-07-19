@extends('hotel.pdf.layout')

@section('content')
<x-pdf-row bold left="PAYSLIP" :right="$line->run->month" />
<x-pdf-row :left="'Employee: '.$line->user->name" />
<x-pdf-row :left="'Role: '.$line->user->roles->pluck('name')->implode(', ')" />
@if($line->user->epf_number)
    <x-pdf-row :left="'EPF No: '.$line->user->epf_number" />
@endif
<x-pdf-row :left="'Hours worked: '.$line->worked_hours.'  ·  OT hours: '.$line->ot_hours" />
<hr class="hr">
<x-pdf-row left="Basic salary" :right="\App\Support\Money::format($line->base_salary)" />
@if($line->ot_pay > 0)
    <x-pdf-row :left="'Overtime ('.$line->ot_hours.' h)'" :right="\App\Support\Money::format($line->ot_pay)" />
@endif
@if($line->allowance > 0)
    <x-pdf-row left="Allowance" :right="\App\Support\Money::format($line->allowance)" />
@endif
@if($line->bonus > 0)
    <x-pdf-row left="Bonus" :right="\App\Support\Money::format($line->bonus)" />
@endif
@if($line->unpaid_leave_deduction > 0)
    <x-pdf-row left="Unpaid leave deduction" :right="'-'.\App\Support\Money::format($line->unpaid_leave_deduction)" />
@endif
<hr class="hr">
<x-pdf-row bold left="GROSS PAY" :right="\App\Support\Money::format($line->gross)" />
@if($line->epf_employee > 0)
    <x-pdf-row left="EPF employee contribution (deducted)" :right="'-'.\App\Support\Money::format($line->epf_employee)" />
@endif
@if($line->apit > 0)
    <x-pdf-row left="APIT (tax)" :right="'-'.\App\Support\Money::format($line->apit)" />
@endif
@if($line->loan > 0)
    <x-pdf-row left="Loan" :right="'-'.\App\Support\Money::format($line->loan)" />
@endif
@if($line->advance > 0)
    <x-pdf-row left="Advance" :right="'-'.\App\Support\Money::format($line->advance)" />
@endif
@if($line->other_deduction > 0)
    <x-pdf-row :left="'Other deduction'.($line->other_deduction_note ? ' ('.$line->other_deduction_note.')' : '')" :right="'-'.\App\Support\Money::format($line->other_deduction)" />
@endif
<hr class="hr">
<x-pdf-row bold left="NET PAY (LKR)" :right="\App\Support\Money::format($line->net_pay)" />

<div style="margin-top:8px; font-size:8px; color:#666">
    Employer contributions (not deducted from pay): EPF {{ \App\Support\Money::format($line->epf_employer) }} · ETF {{ \App\Support\Money::format($line->etf) }} · Employer cost {{ \App\Support\Money::format($line->employer_cost) }}
</div>
<div style="font-size:8px; color:#666">
    Status: {{ $line->paid ? 'PAID on '.$line->paid_at?->format('d/m/Y') : 'PENDING PAYMENT' }}
</div>
@endsection
