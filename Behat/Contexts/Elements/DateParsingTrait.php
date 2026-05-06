<?php

namespace axenox\BDT\Behat\Contexts\Elements;

/**
 * Provides shared date and datetime parsing utilities for UI5 node classes.
 *
 * This trait centralizes all date parsing logic so it is defined in exactly one
 * place and shared between UI5InputNode (validation) and UI5DataNode (comparison).
 * Any new format or locale quirk only needs to be added here.
 */
trait DateParsingTrait
{
    /**
     * Parses a date or datetime string in multiple formats to a Unix timestamp (midnight UTC).
     *
     * Datetime formats are tried before date-only formats so that a value like
     * "15.01.26 14:30" is never partially matched by the date-only pattern "d.m.y".
     *
     * Supported date-only formats:
     *   d.m.Y  — "15.01.2026"   (German long year)
     *   d.m.y  — "15.01.26"     (German 2-digit year, as produced by SAP UI5 date inputs)
     *   Y-m-d  — "2026-01-15"   (ISO)
     *   d/m/Y  — "15/01/2026"
     *   m/d/Y  — "01/15/2026"
     *
     * Supported datetime formats: same date part + " H:i:s" or " H:i" suffix.
     *
     * 2-digit year interpretation follows the standard POSIX rule used by PHP:
     *   00–68  -> 2000–2068
     *   69–99  -> 1969–1999
     *
     * @param string $value Raw date or datetime string to parse
     * @return int|null     Unix timestamp, or null if the value cannot be parsed
     */
    public function parseDateFlexible(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $formats = [
            // Datetime formats first — must come before date-only to prevent partial matching
            'd.m.Y H:i:s',  // "15.01.2026 14:30:00"
            'd.m.Y H:i',    // "15.01.2026 14:30"
            'd.m.y H:i:s',  // "15.01.26 14:30:00"  (2-digit year)
            'd.m.y H:i',    // "15.01.26 14:30"     (2-digit year)
            'Y-m-d H:i:s',  // "2026-01-15 14:30:00"
            'Y-m-d H:i',    // "2026-01-15 14:30"
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            // Date-only formats
            'd.m.Y',        // "15.01.2026"
            'd.m.y',        // "15.01.26"  (2-digit year, produced by SAP UI5 inputs)
            'Y-m-d',        // "2026-01-15"
            'd/m/Y',        // "15/01/2026"
            'm/d/Y',        // "01/15/2026"
        ];

        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat('!' . $format, $value);
            // Strict check: re-format must reproduce the original string exactly,
            // preventing e.g. "32.01.2026" from being accepted as a valid date.
            if ($dt !== false && $dt->format($format) === $value) {
                return $dt->getTimestamp();
            }
        }

        return null;
    }

    /**
     * Normalizes a date or datetime string to a canonical ISO format string.
     *
     * Uses parseDateFlexible() internally so the supported format list is defined
     * in exactly one place. Seconds are always stripped from the output because
     * SAP UI5 date/datetime inputs never display seconds.
     *
     * @param string $value       Raw value coming from the UI or a test step
     * @param string $caption     Caption of the filter for error messages
     * @param bool   $includeTime When true, returns "Y-m-d H:i"; otherwise "Y-m-d"
     * @return string             Normalized ISO string
     * @throws \InvalidArgumentException When the value cannot be parsed as a date
     */
    public function normalizeDateToIso(string $value, string $caption, bool $includeTime = false): string
    {
        $timestamp = $this->parseDateFlexible($value);

        if ($timestamp === null) {
            throw new \InvalidArgumentException(
                "Cannot parse date value `{$value}` in filter '{$caption}'"
            );
        }

        $dt = (new \DateTime())->setTimestamp($timestamp);
        return $dt->format($includeTime ? 'Y-m-d H:i' : 'Y-m-d');
    }
}