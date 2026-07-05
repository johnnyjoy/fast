<?php declare(strict_types = 1);

/**
 * Benchmark table formatters for Fast.
 *
 * @package   Bench
 * @copyright Copyright (c) 2026 johnnyjoy
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://github.com/johnnyjoy/fast
 */
namespace Bench;

/**
 * Markdown and CSV formatters for benchmark result tables.
 *
 * @package Bench
 */
readonly final class Table
{
    /**
     * Render a markdown table from headers and rows.
     *
     * @param list<string>                 $headers Column titles
     * @param list<list<string|int|float>> $rows    Cell values per row
     *
     * @return string Markdown table text
     */
    public static function markdown(array $headers, array $rows): string
    {
        $lines = [];
        $lines[] = '| ' . \implode(' | ', $headers) . ' |';
        $lines[] = '| ' . \implode(' | ', \array_fill(0, \count($headers), '---')) . ' |';

        foreach ($rows as $row) {
            $cells = [];

            foreach ($row as $cell) {
                $cells[] = (string) $cell;
            }

            $lines[] = '| ' . \implode(' | ', $cells) . ' |';
        }

        return \implode("\n", $lines);
    }

    /**
     * Render CSV lines from field names and associative rows.
     *
     * @param list<string>              $fields Column keys
     * @param list<array<string, mixed>> $rows   Row payloads keyed by field
     *
     * @return string CSV text with trailing newline
     */
    public static function csvLines(array $fields, array $rows): string
    {
        $out = [self::csvRow($fields)];

        foreach ($rows as $row) {
            $cells = [];

            foreach ($fields as $field) {
                $cells[] = self::csvCell($row[$field] ?? '');
            }

            $out[] = self::csvRow($cells);
        }

        return \implode("\n", $out) . "\n";
    }

    /**
     * Join cell values into a single CSV row.
     *
     * @param list<string|int|float> $cells Values for one row
     *
     * @return string Escaped CSV row
     */
    public static function csvRow(array $cells): string
    {
        $escaped = [];

        foreach ($cells as $cell) {
            $escaped[] = self::csvCell($cell);
        }

        return \implode(',', $escaped);
    }

    /**
     * Escape and quote a single CSV cell value.
     *
     * @param mixed $value Raw cell value
     *
     * @return string CSV-safe cell text
     */
    public static function csvCell(mixed $value): string
    {
        $s = (string) $value;

        if (\str_contains($s, ',') || \str_contains($s, '"') || \str_contains($s, "\n")) {
            return '"' . \str_replace('"', '""', $s) . '"';
        }

        return $s;
    }

    /**
     * Format a numeric cell without thousands separators.
     *
     * @param float $n         Value to format
     * @param int   $decimals  Decimal places
     *
     * @return string Fixed-point string
     */
    public static function formatNumber(float $n, int $decimals = 0): string
    {
        return \number_format($n, $decimals, '.', '');
    }
}
