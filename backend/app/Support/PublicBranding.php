<?php

namespace App\Support;

use App\Services\Settings;

/**
 * The unauthenticated public identity of a tenant — hotel name, tagline,
 * logo, check-in/out times and theme colours. One payload shared by the
 * /api/public/branding endpoint and the SPA boot gate (host-context), so a
 * tenant page load resolves its identity in a single round-trip instead of
 * two.
 */
class PublicBranding
{
    /** @return array<string, mixed> */
    public static function payload(): array
    {
        return [
            'name' => Settings::str('hotel.name', 'Mount View Hotel'),
            'tagline' => Settings::str('hotel.tagline', 'Hospitality Management System'),
            'login_tagline' => Settings::str('hotel.login_tagline', 'Hospitality Management System'),
            'logo' => Settings::str('hotel.logo_url', ''),
            'address' => Settings::str('hotel.address', ''),
            'phone' => Settings::str('hotel.phone', ''),
            'email' => Settings::str('hotel.email', ''),
            'check_in_time' => Settings::str('frontdesk.check_in_time', '14:00'),
            'check_out_time' => Settings::str('frontdesk.check_out_time', '12:00'),
            'usd_rate' => Settings::num('currency.usd_rate', 300),
            'theme_primary' => Settings::str('theme.primary', '#0462d3'),
            'theme_secondary' => Settings::str('theme.secondary', '#3783f0'),
            'theme_sidebar' => Settings::str('theme.sidebar', '#0c182a'),
        ];
    }
}
