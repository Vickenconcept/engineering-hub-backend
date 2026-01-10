<?php

namespace App\Helpers;

class MoneyFormatter
{
    /**
     * Format money amount with proper thousand separators and decimal places
     * 
     * @param float|string|null $amount
     * @param int $decimals Number of decimal places (default: 2)
     * @param bool $compact Whether to use compact notation (K, M, B) for large numbers
     * @return string|null
     */
    public static function format($amount, int $decimals = 2, bool $compact = false): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        // Convert to float
        $amount = (float) $amount;

        // If compact mode and amount is large, use compact notation
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

        // Standard formatting with thousand separators
        return number_format($amount, $decimals, '.', ',');
    }

    /**
     * Format money amount as a formatted number (for API responses)
     * Returns both raw value and formatted string
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
        $formatted = self::format($amount, $decimals, false);

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

