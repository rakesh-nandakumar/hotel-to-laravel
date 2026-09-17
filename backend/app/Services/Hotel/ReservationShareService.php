<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Reservation;
use App\Models\Hotel\ReservationRoom;
use App\Services\Settings;
use App\Support\Lookups\ReservationStatus;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * The plain-text booking summary staff post into the hotel's own WhatsApp
 * group. The group's invite link is a master-control setting
 * ({@see self::GROUP_LINK_SETTING}); WhatsApp has no URL scheme that opens a
 * group AND pre-fills a message, so the SPA copies this text to the clipboard
 * and opens the link for the staff member to paste into. Composing the text
 * here (not in React) keeps one canonical format for the list and detail
 * screens and makes it testable.
 */
class ReservationShareService
{
    public const GROUP_LINK_SETTING = 'notifications.whatsapp_group_link';

    /**
     * Only a booking that is actually on the books gets announced — a
     * cancelled/no-show one would just mislead the group, and a pending one
     * isn't a booking yet.
     */
    private const SHAREABLE_STATUSES = [
        ReservationStatus::CONFIRMED,
        ReservationStatus::CHECKED_IN,
        ReservationStatus::CHECKED_OUT,
    ];

    public function __construct(
        private readonly BillingService $billing,
        private readonly RoomPricingService $pricing,
    ) {}

    /**
     * @return array{message: string, group_link: string}
     */
    public function whatsAppMessage(Reservation $reservation): array
    {
        $groupLink = trim(Settings::str(self::GROUP_LINK_SETTING, ''));
        if ($groupLink === '') {
            throw ValidationException::withMessages([
                'group_link' => 'No WhatsApp group link is configured — a platform operator can add one in master control under Settings → notifications.',
            ]);
        }

        $reservation->loadMissing(['guest', 'status', 'channel', 'package', 'rooms.room', 'groupBooking', 'corporateAccount', 'folio']);

        if (! in_array($reservation->status->code, self::SHAREABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'reservation' => 'Only a confirmed, checked-in or checked-out booking can be shared to the WhatsApp group.',
            ]);
        }

        return ['message' => $this->compose($reservation), 'group_link' => $groupLink];
    }

    /**
     * WhatsApp renders *asterisks* as bold; everything else is plain lines so
     * the message reads the same in the app, on the web client and in a
     * notification preview.
     */
    private function compose(Reservation $reservation): string
    {
        $nights = count($this->pricing->nights($reservation->check_in, $reservation->check_out));
        $rooms = $reservation->rooms->map(fn (ReservationRoom $rr) => $rr->room?->number)->filter()->implode(', ');

        $lines = [
            '*Booking '.$reservation->code.' — '.strtoupper($reservation->status->name).'*',
            'Guest: '.$reservation->guest->name.($reservation->guest->phone ? ' · '.$reservation->guest->phone : ''),
            'Rooms: '.($rooms !== '' ? $rooms : '—'),
            'Check-in: '.$reservation->check_in->format('D d M Y').' (from '.Settings::str('frontdesk.check_in_time', '14:00').')',
            'Check-out: '.$reservation->check_out->format('D d M Y').' (by '.Settings::str('frontdesk.check_out_time', '12:00').')',
            'Nights: '.$nights.' · Guests: '.$this->occupancy($reservation),
            'Package: '.($reservation->package?->name ?? 'Room only'),
            'Channel: '.$reservation->channel->name,
        ];

        array_push($lines, ...$this->moneyLines($reservation, $nights));

        if ($reservation->groupBooking) {
            $lines[] = 'Group: '.$reservation->groupBooking->reference.' — '.$reservation->groupBooking->name;
        }
        if ($reservation->corporateAccount) {
            $lines[] = 'Corporate: '.$reservation->corporateAccount->company_name;
        }
        if (trim((string) $reservation->notes) !== '') {
            $lines[] = 'Notes: '.trim((string) $reservation->notes);
        }

        return implode("\n", $lines);
    }

    private function occupancy(Reservation $reservation): string
    {
        $parts = [$reservation->adults.' '.($reservation->adults === 1 ? 'adult' : 'adults')];
        if ($reservation->children > 0) {
            $parts[] = $reservation->children.' '.($reservation->children === 1 ? 'child' : 'children');
        }

        return implode(', ', $parts);
    }

    /**
     * Room charges only post to the folio at check-in, so before that the
     * folio total is 0 and the stay total has to be projected from the booked
     * nightly rates (the same figure the booking screen quoted). Once charges
     * exist the folio is the real bill — extras, taxes and all.
     *
     * @return list<string>
     */
    private function moneyLines(Reservation $reservation, int $nights): array
    {
        $folio = $reservation->folio;
        $totals = $folio ? $this->billing->totals($folio) : ['total' => 0, 'paid' => 0, 'refunded' => 0];

        $stayTotal = $totals['total'] > 0 ? $totals['total'] : $this->projectedStayTotal($reservation, $nights);
        $netPaid = $totals['paid'] - $totals['refunded'];

        $lines = ['Stay total: LKR '.Money::format($stayTotal)];

        if ($netPaid > 0) {
            $lines[] = 'Paid: LKR '.Money::format($netPaid).' · Balance: LKR '.Money::format(max($stayTotal - $netPaid, 0));
        } elseif ($reservation->deposit_due > 0 && $reservation->status->code === ReservationStatus::CONFIRMED) {
            $lines[] = 'Deposit due: LKR '.Money::format($reservation->deposit_due);
        }

        return $lines;
    }

    private function projectedStayTotal(Reservation $reservation, int $nights): int
    {
        $rooms = (int) $reservation->rooms->sum(fn (ReservationRoom $rr) => $rr->nightly_rate * $nights);
        $package = (int) ($reservation->package?->price_per_person_per_night ?? 0) * $reservation->adults * $nights;

        return $rooms + $package;
    }
}
