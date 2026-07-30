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
     * WHY THIS EXISTS: callers that only need a timestamp (not the matched format) get a thin wrapper
     * over matchDateFormat, so the accepted-format list lives in exactly one place (getDateFormats).
     *
     * @param string $value Raw date or datetime string to parse
     * @return int|null     Unix timestamp, or null if the value cannot be parsed
     */
    public function parseDateFlexible(string $value): ?int
    {
        return $this->matchDateFormat($value)['timestamp'] ?? null;
    }

    /**
     * Normalizes a date or datetime string to a canonical ISO format string.
     *
     * Uses parseDateFlexible() internally so the supported format list is defined
     * in exactly one place. Seconds are always stripped from the output because
     * SAP UI5 date/datetime inputs never display seconds.
     *
     * An empty value is returned unchanged (see the guard below): it means "no date",
     * not a malformed one, so it must not raise a parse error.
     *
     * @param string $value       Raw value coming from the UI or a test step
     * @param string $caption     Caption of the filter for error messages
     * @param bool   $includeTime When true, returns "Y-m-d H:i"; otherwise "Y-m-d"
     * @return string             Normalized ISO string, or "" when $value is empty
     * @throws \InvalidArgumentException When a non-empty value cannot be parsed as a date
     */
    public function normalizeDateToIso(string $value, string $caption, bool $includeTime = false): string
    {
        // An empty value is the absence of a date (a cleared or never-set filter), not a malformed one.
        // Callers reach this method whenever they compare a date field's current value, and a range
        // filter that was never set legitimately reads back empty. Returning "" here lets every caller
        // compare empty-vs-empty (match) or empty-vs-date (a clean assertion mismatch) instead of dying
        // with "Cannot parse date value ``". A non-empty but unparseable value is still a real error and
        // keeps throwing below. Fixing this at the single throw site covers all callers at once, so no
        // per-caller empty guard is needed.
        if (trim($value) === '') {
            return '';
        }

        $timestamp = $this->parseDateFlexible($value);

        if ($timestamp === null) {
            throw new \InvalidArgumentException(
                "Cannot parse date value `{$value}` in filter '{$caption}'"
            );
        }

        $dt = (new \DateTime())->setTimestamp($timestamp);
        return $dt->format($includeTime ? 'Y-m-d H:i' : 'Y-m-d');
    }

    /**
     * Returns the ordered list of accepted date/datetime formats.
     *
     * WHY THIS EXISTS: the list is needed both to parse a value (parseDateFlexible) and to learn
     * which format an already-displayed value uses (matchDateFormat). Keeping it in one place stops
     * those two consumers from drifting apart. Datetime formats precede date-only ones so a value like
     * "15.01.26 14:30" is never partially matched by the date-only "d.m.y".
     *
     * @return string[]
     */
    private function getDateFormats(): array
    {
        return [
            // Datetime formats first — must come before date-only to prevent partial matching
            'd.m.Y H:i:s', 'd.m.Y H:i',
            'd.m.y H:i:s', 'd.m.y H:i',
            'Y-m-d H:i:s', 'Y-m-d H:i',
            'd/m/Y H:i:s', 'd/m/Y H:i',
            // Date-only formats
            'd.m.Y', 'd.m.y', 'Y-m-d', 'd/m/Y', 'm/d/Y',
        ];
    }

    /**
     * Matches a raw date/datetime string against the accepted formats and reports which one hit.
     *
     * WHY THIS EXISTS: comparing a value we typed against the value a UI5 date input echoes back
     * requires knowing the format the field displays in (2-digit vs 4-digit year, with/without time).
     * parseDateFlexible discards that information; this method keeps it so a caller can re-render an
     * expected value in exactly the field's own format before comparing.
     *
     * @param string $value Raw date or datetime string
     * @return array{timestamp:int, format:string}|null Null if no accepted format matches
     */
    private function matchDateFormat(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        foreach ($this->getDateFormats() as $format) {
            $dt = \DateTime::createFromFormat('!' . $format, $value);
            // Strict check: re-format must reproduce the input exactly so "32.01.2026" is rejected.
            if ($dt !== false && $dt->format($format) === $value) {
                return ['timestamp' => $dt->getTimestamp(), 'format' => $format];
            }
        }
        return null;
    }

    /**
     * Tells whether a value we set equals what a date input echoed back, compared at the precision
     * the input can actually display.
     *
     * WHY THIS EXISTS: a UI5 date input configured with a 2-digit year (dd.MM.yy) physically cannot
     * echo back a value's century. When the works-as-expected routine sources a real filter value whose
     * year falls outside the 2-digit pivot window (e.g. the data source's earliest row is year 202 ->
     * "0202-07-01"), the field displays "01.07.02" and reading it back reconstructs year 2002. A
     * full-year (Y-m-d) equality check then reports a mismatch for a field that in fact accepted the
     * value. Re-rendering the expected value in the field's own display format (learned from the actual
     * value) compares only what the input can represent: it still catches a real day/month/2-digit-year
     * mismatch, but does not fail on a century the field never showed.
     *
     * @param string $expected Value that was typed into the input
     * @param string $actual   Value the input currently displays (read back from the DOM)
     * @return bool
     */
    public function datesEqualAtDisplayPrecision(string $expected, string $actual): bool
    {
        $expectedTimestamp = $this->parseDateFlexible($expected);
        $actualMatch       = $this->matchDateFormat($actual);
        if ($expectedTimestamp === null || $actualMatch === null) {
            return false;
        }
        // Render the value we set into the field's own display format, then compare to what the field
        // shows. This compares exactly what the input can represent — no more, no less.
        $expectedInActualFormat = (new \DateTime())
            ->setTimestamp($expectedTimestamp)
            ->format($actualMatch['format']);
        return $expectedInActualFormat === trim($actual);
    }
}