{{--
    Settlement block shared by the guest/venue invoice and the POS receipt.
    Every payment is listed at face value (a deposit, a cash tender, a card
    slip…), then the money that went BACK to the guest — the change on a
    cash over-tender, and any genuine refund — so the three figures a
    cashier and a guest both look for read straight down the right-hand
    column: what was handed over, what came back, what's still owed.

    Inputs: $payments (Payment collection with kind + method loaded, oldest
    first) and $total (int LKR cents).
--}}
@use('App\Models\Hotel\Payment')
@use('App\Support\Lookups\PaymentKind')
@use('App\Support\Lookups\PaymentMethod')
@use('App\Support\Money')
@php
    $received = $payments->reject(fn (Payment $p) => $p->isRefund());
    $change = $payments->filter(fn (Payment $p) => $p->isChange());
    $refunds = $payments->filter(fn (Payment $p) => $p->isRefund() && ! $p->isChange());

    $totalReceived = (int) $received->sum('amount');
    $changeReturned = (int) $change->sum('amount');
    $refunded = (int) $refunds->sum('amount');
    $netPaid = $totalReceived - $changeReturned - $refunded;
    $balance = $total - $netPaid;

    $when = fn (Payment $p) => ' · '.$p->created_at->format('d/m/Y');
    $via = fn (Payment $p) => strtoupper($p->method->code).($p->reference ? ' ('.$p->reference.')' : '');
    // "Cash tendered" is the figure a guest checks against the change they got.
    $receivedLabel = fn (Payment $p) => match (true) {
        $p->kind->code === PaymentKind::DEPOSIT => 'Deposit — '.$via($p).$when($p),
        $p->method->code === PaymentMethod::CASH => 'Cash tendered'.$when($p),
        default => 'Paid — '.$via($p).$when($p),
    };
@endphp

@if($payments->isNotEmpty())
    @foreach($received as $payment)
        <x-pdf-row :left="$receivedLabel($payment)" :right="Money::format($payment->amount)" />
    @endforeach

    {{-- A single payment IS the total received — no subtotal repeating it. --}}
    @if($received->count() > 1)
        <x-pdf-row bold left="TOTAL RECEIVED" :right="Money::format($totalReceived)" />
    @endif

    @if($changeReturned > 0)
        <x-pdf-row bold left="CHANGE RETURNED — CASH" :right="'-'.Money::format($changeReturned)" />
    @endif

    @foreach($refunds as $refund)
        <x-pdf-row :left="'Refund — '.$via($refund).$when($refund).($refund->reason ? ' — '.$refund->reason : '')" :right="'-'.Money::format($refund->amount)" />
    @endforeach

    @if($changeReturned > 0 || $refunded > 0)
        <hr class="hr">
        <x-pdf-row bold left="NET PAID" :right="Money::format($netPaid)" />
    @endif
@endif

@if($balance < 0)
    <x-pdf-row bold left="REFUND DUE TO GUEST" :right="Money::format(-$balance)" />
@else
    <x-pdf-row bold :left="$balance > 0 ? 'BALANCE DUE' : 'BALANCE'" :right="Money::format($balance)" />
@endif
