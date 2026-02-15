<?php
/**
 * Card Graph — Filename Date Parser
 *
 * Extracts start and end dates from Whatnot earnings CSV filenames.
 * Format: december_15_december_21_2025_earnings.csv
 *         <start_month>_<start_day>_<end_month>_<end_day>_<year>_earnings.csv
 */
class FilenameParser
{
    private static array $months = [
        'january'   => 1,  'february' => 2,  'march'     => 3,
        'april'     => 4,  'may'      => 5,  'june'      => 6,
        'july'      => 7,  'august'   => 8,  'september' => 9,
        'october'   => 10, 'november' => 11, 'december'  => 12,
    ];

    /**
     * Parse an earnings CSV filename and extract start/end dates.
     *
     * @return array{start_date: string, end_date: string} Dates in Y-m-d format
     * @throws InvalidArgumentException if filename doesn't match expected pattern
     */
    public static function parse(string $filename): array
    {
        // Remove path, keep just the filename
        $basename = basename($filename);
        $lower = strtolower($basename);

        // Must end with _earnings.csv
        if (!str_ends_with($lower, '_earnings.csv')) {
            throw new InvalidArgumentException(
                "Filename must end with '_earnings.csv'. Got: {$basename}"
            );
        }

        // Strip the _earnings.csv suffix
        $datePart = substr($lower, 0, -strlen('_earnings.csv'));

        // Pattern: month_day_month_day_year
        $pattern = '/^([a-z]+)_(\d{1,2})_([a-z]+)_(\d{1,2})_(\d{4})$/';
        if (!preg_match($pattern, $datePart, $m)) {
            throw new InvalidArgumentException(
                "Cannot parse dates from filename: {$basename}. Expected format: month_day_month_day_year_earnings.csv"
            );
        }

        $startMonth = self::$months[$m[1]] ?? null;
        $startDay   = (int) $m[2];
        $endMonth   = self::$months[$m[3]] ?? null;
        $endDay     = (int) $m[4];
        $year       = (int) $m[5];

        if (!$startMonth) {
            throw new InvalidArgumentException("Unknown month: {$m[1]}");
        }
        if (!$endMonth) {
            throw new InvalidArgumentException("Unknown month: {$m[3]}");
        }

        // Handle year boundary (e.g., december_29_january_4_2026)
        $startYear = $year;
        $endYear = $year;
        if ($endMonth < $startMonth) {
            $endYear = $year + 1;
        }

        $startDate = sprintf('%04d-%02d-%02d', $startYear, $startMonth, $startDay);
        $endDate   = sprintf('%04d-%02d-%02d', $endYear, $endMonth, $endDay);

        // Validate the dates are real
        if (!checkdate($startMonth, $startDay, $startYear)) {
            throw new InvalidArgumentException("Invalid start date: {$startDate}");
        }
        if (!checkdate($endMonth, $endDay, $endYear)) {
            throw new InvalidArgumentException("Invalid end date: {$endDate}");
        }

        return [
            'start_date' => $startDate,
            'end_date'   => $endDate,
        ];
    }
}
