<?php

namespace App\Helpers;

/**
 * Pure helpers for "import:large-dataset" (no database, no framework), so they
 * can be unit tested on their own. See LargeImportScan for the first pass.
 */
class LargeImportHelper
{
    /** columns CCDT adds to every table itself */
    const RESERVED = ['id', 'srchindex', 'created_at', 'updated_at'];

    /** field-name parts that keep a value out of the search index (as the original command did) */
    const SEARCH_EXCLUDE = ['RECORD TYPE', 'FLAG', 'DATE', 'CMSID', 'SEQUENCE NUMBER', 'CREATED_AT', 'UPDATED_AT'];

    /**
     * Name of the table for one record type, e.g. ("cases", "2A") => "2a_cases".
     */
    public static function tableName($base, $recordType)
    {
        $base = strtolower(preg_replace('/[^A-Za-z0-9_]+/', '_', trim($base, "` \t\n")));
        $base = trim($base, '_');
        $type = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $recordType));
        $name = $type . '_' . ($base === '' ? 'import' : $base);
        return substr($name, 0, 64);
    }

    /**
     * Column names for a header: formatted the way CCDT formats every field
     * name (lower case, underscores), made unique, and never one of the
     * columns CCDT adds itself.
     *
     * @param string[] $header   field names, e.g. ["Record Type", "Constituent ID"]
     * @param callable $format   CustomStringHelper::formatFieldName
     * @return string[] same length and order as $header
     */
    public static function columnNames(array $header, callable $format)
    {
        $out = [];
        $seen = [];
        foreach ($header as $i => $name) {
            $col = $format($name);
            if ($col === '') {
                $col = 'field' . $i;
            }
            if (in_array($col, self::RESERVED, true)) {
                $col .= '_field';
            }
            $base = $col;
            $n = 2;
            while (isset($seen[$col])) {
                $col = $base . '_' . $n++;
            }
            $seen[$col] = true;
            $out[] = $col;
        }
        return $out;
    }

    /**
     * Make a row have exactly $count fields: short rows are padded with blanks,
     * extra fields are folded back into the last one (an unescaped delimiter
     * inside the last value).
     */
    public static function normalize(array $tokens, $count, $delimiter)
    {
        $n = count($tokens);
        if ($n === $count) {
            return $tokens;
        }
        if ($n < $count) {
            return array_pad($tokens, $count, '');
        }
        $head = array_slice($tokens, 0, $count - 1);
        $head[] = implode($delimiter, array_slice($tokens, $count - 1));
        return $head;
    }

    /**
     * Text for the search index: the row's values, except the record type,
     * dates, flags, sequence numbers and IDs (the constituent ID is kept so
     * records can be found by it). Rows of record types without a known layout
     * contribute everything after the record type.
     *
     * @param string[] $header field names of the layout (e.g. "Date In") or generated ones
     * @param string[] $values the row, same order
     * @param boolean  $known  false when $header is generated (Field0, Field1...)
     */
    public static function searchIndex(array $header, array $values, $known)
    {
        $terms = [];
        foreach ($values as $i => $value) {
            if ($i === 0 || $value === '') {
                continue;       // the record type is never searched
            }
            if ($known && isset($header[$i]) && self::excludedFromSearch($header[$i])) {
                continue;
            }
            $terms[] = $value;
        }
        return implode(' ', $terms);
    }

    public static function excludedFromSearch($fieldName)
    {
        $upper = strtoupper($fieldName);
        if ($upper === 'CONSTITUENT ID') {
            return false;
        }
        foreach (self::SEARCH_EXCLUDE as $part) {
            if (strpos($upper, $part) !== false) {
                return true;
            }
        }
        // "ID" as a word (Address ID, Household ID), not inside other words such as "Individual"
        return (bool) preg_match('/\bID\b/', $upper);
    }

    /**
     * Split one line of the file into trimmed fields, converting from ISO-8859-1
     * (the encoding of the original exports) when the line is not valid UTF-8.
     *
     * @return string[]|null null for blank lines and lines that are not records
     *         (no delimiter, or a first field that is not a record type such as "*****")
     */
    public static function tokens($line, $delimiter)
    {
        $line = rtrim($line, "\r\n");
        if (trim($line) === '') {
            return null;
        }
        if (!preg_match('//u', $line)) {
            $line = mb_convert_encoding($line, 'UTF-8', 'ISO-8859-1');
        }
        $tokens = array_map('trim', explode($delimiter, $line));
        if (count($tokens) < 2 || !preg_match('/^[A-Za-z0-9]{1,6}$/', $tokens[0])) {
            return null;
        }
        return $tokens;
    }
}
