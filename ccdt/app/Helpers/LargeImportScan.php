<?php

namespace App\Helpers;

/**
 * First pass of "import:large-dataset": learns, for every record type in a
 * mixed delimited file, how many fields its rows have and what type each
 * column must be, without keeping any rows in memory.
 *
 * Column typing follows the same rules as the tab-delimited import
 * (CSVHelper::checkFile): a column is an integer column only if every value is
 * a plain integer; anything else (text, blanks, leading zeros, decimals,
 * redaction placeholders) makes it text for good, and the longest value
 * decides the size.
 *
 * Rows of one record type may have different field counts (exports drop
 * trailing empty fields). The usual count decides the table layout; shorter
 * rows are padded with blanks, which makes the padded columns text, and longer
 * rows have their extra fields folded back into the last column.
 */
class LargeImportScan
{
    /** distinct field counts remembered per record type (a runaway file cannot grow memory) */
    const MAX_DISTINCT_COUNTS = 1000;

    private $csv;
    private $types = [];

    public function __construct(CSVHelper $csv = null)
    {
        $this->csv = $csv ?: new CSVHelper();
    }

    /**
     * Record one row.
     *
     * @param string $recordType e.g. "2A"
     * @param array  $tokens     the row's trimmed fields, record type included
     */
    public function add($recordType, array $tokens)
    {
        if (!isset($this->types[$recordType])) {
            $this->types[$recordType] = ['rows' => 0, 'hist' => [], 'pos' => []];
        }
        $t =& $this->types[$recordType];
        $t['rows']++;

        $count = count($tokens);
        if (isset($t['hist'][$count]) || count($t['hist']) < self::MAX_DISTINCT_COUNTS) {
            $t['hist'][$count] = (isset($t['hist'][$count]) ? $t['hist'][$count] : 0) + 1;
        }

        foreach ($tokens as $i => $value) {
            if (!isset($t['pos'][$i])) {
                $t['pos'][$i] = [0, 0, false];     // same shape as CSVHelper::checkFile
            }
            if (!$t['pos'][$i][2] && $this->csv->isPlainInteger($value)) {
                $t['pos'][$i][0] = 1;
            } else {
                $t['pos'][$i][0] = 0;
                $t['pos'][$i][2] = true;
            }
            $len = strlen($value);
            if ($t['pos'][$i][1] < $len) {
                $t['pos'][$i][1] = $len;
            }
        }
    }

    /** @return string[] record types seen, in order of first appearance */
    public function recordTypes()
    {
        return array_keys($this->types);
    }

    public function rowCount($recordType)
    {
        return isset($this->types[$recordType]) ? $this->types[$recordType]['rows'] : 0;
    }

    /**
     * Decide the table layout for a record type.
     *
     * @param string        $recordType
     * @param callable|null $layoutFinder function ($recordType, $usualCount) returning the
     *                      field names of the matching layout (the first one with at least
     *                      that many fields) or null when the type is not known
     * @return array ['count' => fields in the table, 'header' => field names,
     *                'check' => column findings for CSVHelper::fieldTypesFromCheck,
     *                'rows' => rows seen, 'padded' => rows with fewer fields,
     *                'folded' => rows with more fields, 'known' => layout found,
     *                'extended' => fields added to a layout that is shorter than the rows]
     */
    public function resolve($recordType, callable $layoutFinder = null)
    {
        $t = $this->types[$recordType];
        $hist = $t['hist'];

        // the usual field count: the most common, the larger on a tie
        $usual = 0;
        $best = -1;
        foreach ($hist as $count => $n) {
            if ($n > $best || ($n === $best && $count > $usual)) {
                $best = $n;
                $usual = $count;
            }
        }

        $layout = $layoutFinder ? $layoutFinder($recordType, $usual) : null;
        $known = is_array($layout) && count($layout) > 0;
        $n = $known ? count($layout) : $usual;
        $header = $known ? array_values($layout) : self::generatedHeader($n);
        $extended = 0;
        if ($known && $usual > $n) {
            // the rows have more fields than the largest layout: keep the layout's
            // names and give the extra trailing fields generated ones
            $generated = self::generatedHeader($usual);
            for ($i = $n; $i < $usual; $i++) {
                $header[] = $generated[$i];
            }
            $extended = $usual - $n;
            $n = $usual;
        }

        $check = [];
        $padded = 0;
        $folded = 0;
        foreach ($hist as $count => $rows) {
            if ($count < $n) {
                $padded += $rows;
            } elseif ($count > $n) {
                $folded += $rows;
            }
        }

        for ($p = 0; $p < $n; $p++) {
            $entry = isset($t['pos'][$p]) ? $t['pos'][$p] : [0, 0, false];
            // rows too short to have this field get a blank there
            foreach ($hist as $count => $rows) {
                if ($count <= $p && $rows > 0) {
                    $entry[0] = 0;
                    $entry[2] = true;
                    break;
                }
            }
            $check[$p] = $entry;
        }

        if ($folded > 0 && $n > 0) {
            // everything past the last column is folded into it, with the delimiter between
            $extra = 0;
            foreach ($t['pos'] as $p => $e) {
                if ($p >= $n) {
                    $extra += $e[1] + 1;
                }
            }
            $check[$n - 1] = [0, $check[$n - 1][1] + $extra, true];
        }

        return ['count' => $n, 'header' => $header, 'check' => $check, 'rows' => $t['rows'],
                'padded' => $padded, 'folded' => $folded, 'known' => $known, 'extended' => $extended];
    }

    public static function generatedHeader($count)
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = 'Field' . $i;
        }
        return $out;
    }
}
