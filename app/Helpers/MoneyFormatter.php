<?php

namespace App\Helpers;

class MoneyFormatter
{
    /**
     * Format money amount with proper thousand separators and decimal places
     * Automatically uses compact notation (K, M, B) for large numbers
     * 
     * @param float|string|null $amount
     * @param int $decimals Number of decimal places (default: 2)
     * @param bool $compact Whether to force compact notation (default: auto-detect for amounts >= 1000)
     * @return string|null
     */
    public static function format($amount, int $decimals = 2, bool $compact = null): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        // Convert to float
        $amount = (float) $amount;

        // Auto-detect: use compact notation for amounts >= 1000 if not explicitly disabled
        if ($compact === null) {
            $compact = $amount >= 1000;
        }

        // Use compact notation for large numbers
        if ($compact) {
            if ($amount >= 1000000000) {
                // Billions
                $formatted = number_format($amount / 1000000000, $decimals, '.', '');
                return rtrim(rtrim($formatted, '0'), '.') . 'B';
            } elseif ($amount >= 1000000) {
                // Millions
                $formatted = number_format($amount / 1000000, $decimals, '.', '');
                return rtrim(rtrim($formatted, '0'), '.') . 'M';
            } elseif ($amount >= 1000) {
                // Thousands
                $formatted = number_format($amount / 1000, $decimals, '.', '');
                return rtrim(rtrim($formatted, '0'), '.') . 'K';
            }
        }

        // Standard formatting with thousand separators for amounts < 1000
        return number_format($amount, $decimals, '.', ',');
    }

    /**
     * Format money amount as a formatted number (for API responses)
     * Returns both raw value and formatted string
     * Uses compact notation automatically for large amounts
     * 
     * @param float|string|null $amount
     * @param int $decimals Number of decimal places (default: 2)
     * @return array{raw: float|null, formatted: string|null}
     */
    public static function formatForApi($amount, int $decimals = 2): array
    {
        if ($amount === null || $amount === '') {
            return [
                'raw' => null,
                'formatted' => null,
            ];
        }

        $raw = (float) $amount;
        // Use default format which auto-detects compact notation
        $formatted = self::format($amount, $decimals);

        return [
            'raw' => $raw,
            'formatted' => $formatted,
        ];
    }

    /**
     * Format money amount with currency symbol
     * 
     * @param float|string|null $amount
     * @param string $currency Currency symbol (default: ₦)
     * @param int $decimals Number of decimal places (default: 2)
     * @return string|null
     */
    public static function formatWithCurrency($amount, string $currency = '₦', int $decimals = 2): ?string
    {
        $formatted = self::format($amount, $decimals);
        
        if ($formatted === null) {
            return null;
        }

        return $currency . $formatted;
    }
}

