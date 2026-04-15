<?php

namespace App\Support;

class BillingCycle
{
    private const VALUES = ['monthly', 'yearly', 'one_time'];

    private const ALIASES = [
        'month' => 'monthly',
        'per_month' => 'monthly',
        'year' => 'yearly',
        'annual' => 'yearly',
        'annually' => 'yearly',
        'per_year' => 'yearly',
        'onetime' => 'one_time',
        'once' => 'one_time',
    ];

    public static function values(): array
    {
        return self::VALUES;
    }

    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(['-', ' '], '_', $normalized);

        if (in_array($normalized, self::VALUES, true)) {
            return $normalized;
        }

        return self::ALIASES[$normalized] ?? null;
    }

    public static function addonValue(mixed $addonBillingCycle, mixed $parentBillingCycle): ?string
    {
        return self::normalize($addonBillingCycle) ?? self::normalize($parentBillingCycle);
    }

    public static function label(mixed $value): ?string
    {
        return match (self::normalize($value)) {
            'monthly' => 'monthly',
            'yearly' => 'yearly',
            'one_time' => 'one-time',
            default => is_string($value) ? $value : null,
        };
    }
}
