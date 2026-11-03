<?php

namespace App\Helpers;

class PhoneNumberHelper
{
    /**
     * Normalize any phone number format (WhatsApp, with +880, dashes, spaces)
     * to a clean 11-digit Bangladeshi mobile number (01[3-9]XXXXXXXX).
     *
     * Examples:
     *  - "8801712345678"  -> "01712345678"
     *  - "+8801712345678" -> "01712345678"
     *  - "01712-345678"   -> "01712345678"
     *  - "01712345678"    -> "01712345678"
     */
    public static function toLocalBd(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        // Strip everything except digits
        $digits = preg_replace('/[^\d]/', '', $phone);

        // 13 digits starting with 8801[3-9]
        if (str_starts_with($digits, '8801') && strlen($digits) === 13) {
            $candidate = substr($digits, 2);
            if (static::isValidBdMobile($candidate)) {
                return $candidate;
            }
        }

        // 11 digits starting with 01[3-9]
        if (str_starts_with($digits, '01') && strlen($digits) === 11) {
            if (static::isValidBdMobile($digits)) {
                return $digits;
            }
        }

        // 10 digits starting with 1[3-9]
        if (str_starts_with($digits, '1') && strlen($digits) === 10) {
            $candidate = '0' . $digits;
            if (static::isValidBdMobile($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Validate standard Bangladeshi 11-digit mobile operator prefix (013 - 019).
     */
    public static function isValidBdMobile(string $phone): bool
    {
        return (bool) preg_match('/^01[3-9]\d{8}$/', $phone);
    }

    /**
     * Convert an 11-digit BD mobile number to E.164 format without '+'
     * as required by WhatsApp Cloud API (e.g. 8801712345678).
     */
    public static function toWhatsAppE164(string $localPhone): string
    {
        $clean = preg_replace('/[^\d]/', '', $localPhone);

        if (str_starts_with($clean, '01') && strlen($clean) === 11) {
            return '88' . $clean;
        }

        if (str_starts_with($clean, '8801') && strlen($clean) === 13) {
            return $clean;
        }

        return $clean;
    }
}
