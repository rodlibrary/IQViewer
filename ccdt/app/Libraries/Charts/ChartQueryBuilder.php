<?php

namespace App\Libraries\Charts;

use App\Helpers\TableIndexHelper;

/**
 * Turns a chart spec (plain array, usually decoded from the browser's JSON)
 * into ONE parameterized aggregate SQL query, and turns the result rows into
 * the series structure the matplotlib service draws.
 *
 * This class is deliberately free of Laravel / database dependencies so it can
 * be unit tested on its own: the caller supplies the table name(s) and the
 * column list(s) (column name => MySQL type, as returned by DESCRIBE) and runs
 * the SQL.
 *
 * Safety rules enforced here:
 *   - the browser never sends SQL; every identifier must be a column of one of
 *     the tables (whitelist) and is back-tick quoted
 *   - every value is a bound parameter
 *   - id / srchindex / timestamp columns, free-text column types and columns
 *     whose name matches config('charts.blocked_columns') are refused as
 *     dimension, measure and filter
 *   - result size is capped (categories, series, buckets)
 *   - cells built from fewer than min_cell_size records are suppressed
 *   - a join is only allowed between two ID columns (config index_columns), and
 *     rows whose join key is blank never match
 *
 * Spec format:
 *   {
 *     "chart":   "line|bar|hbar|stacked|hist|heatmap",
 *     "x":       {"column": "date_in", "bucket": "none|month|year", "bin": 1, "src": "a"}
 *             or {"kind": "days_between", "from": "date_in", "to": "date_out", "cap": 365, "bin": 1,
 *                 "from_src": "a", "to_src": "a"},
 *     "group":   {"column": "communication_type", "src": "a"},     (line, stacked, heatmap)
 *     "measure": {"fn": "count|count_distinct|sum|avg", "column": "...", "src": "a"},
 *     "filters": [{"column": "status", "op": "eq", "value": "C", "src": "a"}],
 *     "join":    {"left": "address_id", "right": "address_id"},    (optional; see below)
 *     "limit":   20,                                               (categories shown)
 *     "title":   "optional"
 *   }
 *
 * "src" says which table a column belongs to: "a" (default) is the main table,
 * "b" the table joined to it (inner join, a.left = b.right). Counts are counts
 * of joined rows. With no join the SQL has no table aliases.
 *
 * Written to run on PHP 7.3 (the version CCDT is built on).
 */
class ChartQueryBuilder
{
    const CHARTS   = ['line', 'bar', 'hbar', 'stacked', 'hist', 'heatmap'];
    const MEASURES = ['count', 'count_distinct', 'sum', 'avg'];
    const BUCKETS  = ['none', 'month', 'year'];
    const OPS      = ['eq', 'neq', 'in', 'gte', 'lte', 'between', 'prefix', 'blank', 'notblank'];
    const BINS     = [1, 7, 10, 30, 100, 1000];

    /** Columns CCDT adds to every imported table. */
    const SYSTEM_COLUMNS = ['id', 'srchindex', 'created_at', 'updated_at'];

    /** Label used for empty values and for folded-away series. */
    const BLANK = '(blank)';
    const OTHER = 'Other';

    private $tables = ['a' => null, 'b' => null];
    private $cols = ['a' => [], 'b' => []];
    private $joined = false;
    private $cfg;

    /**
     * @param string $table   main table name (validated)
     * @param array  $columns column name => MySQL column type (e.g. "int(11)", "varchar(30)")
     * @param array  $config  overrides for config/charts.php values
     * @param array  $join    optional second table: ['table' => name, 'columns' => name => type]
     */
    public function __construct($table, array $columns, array $config = [], array $join = null)
    {
        $this->tables['a'] = $this->checkTableName($table);
        $this->cols['a'] = $columns;
        if ($join !== null) {
            $this->tables['b'] = $this->checkTableName($join['table']);
            $this->cols['b'] = $join['columns'];
            $this->joined = true;
        }
        $this->cfg = array_merge([
            'blocked_columns' => [],
            'index_columns'   => ['/_id$/'],
            'index_exclude'   => [],
            'max_categories'  => 50,
            'max_series'      => 8,
            'max_buckets'     => 2000,
            'max_filters'     => 8,
            'max_in_values'   => 50,
            'min_cell_size'   => 10,
            'timeout_ms'      => 30000,
        ], $config);
    }

    private function checkTableName($table)
    {
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', (string) $table)) {
            throw new ChartSpecException('Invalid table name.');
        }
        return $table;
    }

    // ------------------------------------------------------------------
    // Column rules
    // ------------------------------------------------------------------

    /**
     * Columns of one table the builder is willing to use, as
     * name => ['type' => ..., 'numeric' => bool, 'id' => bool].
     * Used both for validation and to populate the page's drop-downs.
     *
     * @param string $src 'a' (main table) or 'b' (joined table)
     */
    public function usableColumns($src = 'a')
    {
        $out = [];
        foreach ($this->cols[$src] as $name => $type) {
            if ($this->isUsable($name, $type)) {
                $out[$name] = ['type' => $type, 'numeric' => $this->isNumericType($type),
                               'id' => TableIndexHelper::isIdColumn($name, $this->cfg)];
            }
        }
        return $out;
    }

    private function isNumericType($type)
    {
        return (bool) preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double)/i', $type);
    }

    private function isTextType($type)
    {
        return (bool) preg_match('/^(tinytext|text|mediumtext|longtext|blob|mediumblob|longblob|json)/i', $type);
    }

    private function isUsable($name, $type)
    {
        if (in_array(strtolower($name), self::SYSTEM_COLUMNS, true)) {
            return false;
        }
        if ($this->isTextType($type)) {
            return false;
        }
        foreach ($this->cfg['blocked_columns'] as $pattern) {
            if (preg_match($pattern, $name)) {
                return false;
            }
        }
        return true;
    }

    private function src($ref, $key = 'src')
    {
        $src = isset($ref[$key]) ? $ref[$key] : 'a';
        if ($src !== 'a' && $src !== 'b') {
            throw new ChartSpecException('Unknown table reference.');
        }
        if ($src === 'b' && !$this->joined) {
            throw new ChartSpecException('This chart uses a second table but none is joined.');
        }
        return $src;
    }

    /** Quoted (and, with a join, alias-qualified) column, after whitelist checks. */
    private function col($name, $src = 'a')
    {
        if (!is_string($name) || !array_key_exists($name, $this->cols[$src])) {
            throw new ChartSpecException('Unknown column: ' . (is_scalar($name) ? $name : '?'));
        }
        if (!$this->isUsable($name, $this->cols[$src][$name])) {
            throw new ChartSpecException("Column '{$name}' cannot be used in charts (free text, personal data or system column).");
        }
        $q = '`' . str_replace('`', '``', $name) . '`';
        return $this->joined ? $src . '.' . $q : $q;
    }

    private function isNumeric($name, $src = 'a')
    {
        return $this->isNumericType($this->cols[$src][$name]);
    }

    // ------------------------------------------------------------------
    // Spec validation
    // ------------------------------------------------------------------

    /**
     * Validate a spec and return it in canonical form (defaults filled in).
     *
     * @throws ChartSpecException
     */
    public function normalize(array $spec)
    {
        $chart = isset($spec['chart']) ? $spec['chart'] : null;
        if (!in_array($chart, self::CHARTS, true)) {
            throw new ChartSpecException('Choose a chart type.');
        }

        $join = $this->normalizeJoin(isset($spec['join']) ? $spec['join'] : null);
        $x = $this->normalizeX(isset($spec['x']) ? $spec['x'] : null, $chart);

        $group = null;
        if (!empty($spec['group']['column'])) {
            if (!in_array($chart, ['line', 'stacked', 'heatmap'], true)) {
                throw new ChartSpecException('Only line, stacked and heatmap charts can be split by a second field.');
            }
            $gsrc = $this->src($spec['group']);
            $this->col($spec['group']['column'], $gsrc);
            $group = ['column' => $spec['group']['column'], 'src' => $gsrc];
        } elseif (in_array($chart, ['stacked', 'heatmap'], true)) {
            throw new ChartSpecException('This chart type needs a second field to split by.');
        }

        $measure = $this->normalizeMeasure(isset($spec['measure']) ? $spec['measure'] : []);

        $filters = [];
        $rawFilters = isset($spec['filters']) && is_array($spec['filters']) ? $spec['filters'] : [];
        if (count($rawFilters) > $this->cfg['max_filters']) {
            throw new ChartSpecException('Too many filters (max ' . $this->cfg['max_filters'] . ').');
        }
        foreach ($rawFilters as $f) {
            $filters[] = $this->normalizeFilter($f);
        }

        $limit = isset($spec['limit']) ? (int) $spec['limit'] : 20;
        $limit = max(1, min($limit, $this->cfg['max_categories']));

        return [
            'chart'   => $chart,
            'x'       => $x,
            'group'   => $group,
            'measure' => $measure,
            'filters' => $filters,
            'join'    => $join,
            'limit'   => $limit,
            'title'   => isset($spec['title']) ? mb_substr(trim((string) $spec['title']), 0, 120) : '',
        ];
    }

    /**
     * Validate just the join part of a spec (used before the second table's
     * indexes are checked).
     *
     * @return array|null ['left' => ..., 'right' => ...]
     * @throws ChartSpecException
     */
    public function checkJoin($join)
    {
        return $this->normalizeJoin($join);
    }

    private function normalizeJoin($j)
    {
        if (!$this->joined) {
            if (is_array($j) && !empty($j)) {
                throw new ChartSpecException('No second table is available for this chart.');
            }
            return null;
        }
        if (!is_array($j) || empty($j['left']) || empty($j['right'])) {
            throw new ChartSpecException('Choose the ID fields that link the two tables.');
        }
        $this->col($j['left'], 'a');
        $this->col($j['right'], 'b');
        foreach ([$j['left'], $j['right']] as $name) {
            if (!TableIndexHelper::isIdColumn($name, $this->cfg)) {
                throw new ChartSpecException("Tables can only be linked on ID fields; '{$name}' is not one.");
            }
        }
        return ['left' => $j['left'], 'right' => $j['right']];
    }

    private function normalizeX($x, $chart)
    {
        if (!is_array($x)) {
            throw new ChartSpecException('Choose a field for the X axis.');
        }

        if (isset($x['kind']) && $x['kind'] === 'days_between') {
            if ($chart !== 'hist') {
                throw new ChartSpecException('"Days between two dates" is only available for histograms.');
            }
            $fs = $this->src($x, 'from_src');
            $ts = $this->src($x, 'to_src');
            $this->col(isset($x['from']) ? $x['from'] : null, $fs);
            $this->col(isset($x['to']) ? $x['to'] : null, $ts);
            $cap = isset($x['cap']) ? (int) $x['cap'] : 365;
            $bin = $this->normalizeBin(isset($x['bin']) ? $x['bin'] : 1);
            return ['kind' => 'days_between', 'from' => $x['from'], 'from_src' => $fs, 'to' => $x['to'], 'to_src' => $ts,
                    'cap' => max($bin, min($cap, 3650)), 'bin' => $bin];
        }

        $src = $this->src($x);
        $this->col(isset($x['column']) ? $x['column'] : null, $src);
        $bucket = isset($x['bucket']) ? $x['bucket'] : 'none';
        if (!in_array($bucket, self::BUCKETS, true)) {
            throw new ChartSpecException('Unknown date grouping.');
        }

        if ($chart === 'hist') {
            if (!$this->isNumeric($x['column'], $src)) {
                throw new ChartSpecException('Histograms need a numeric field (or "days between two dates").');
            }
            return ['kind' => 'bin', 'column' => $x['column'], 'src' => $src, 'bin' => $this->normalizeBin(isset($x['bin']) ? $x['bin'] : 1)];
        }
        if ($chart === 'line' && $bucket === 'none') {
            throw new ChartSpecException('Line charts need a date field grouped by month or year.');
        }
        return ['kind' => $bucket === 'none' ? 'category' : 'time', 'column' => $x['column'], 'src' => $src, 'bucket' => $bucket];
    }

    private function normalizeBin($bin)
    {
        $bin = (int) $bin;
        if (!in_array($bin, self::BINS, true)) {
            throw new ChartSpecException('Unsupported bin size.');
        }
        return $bin;
    }

    private function normalizeMeasure($m)
    {
        $fn = isset($m['fn']) ? $m['fn'] : 'count';
        if (!in_array($fn, self::MEASURES, true)) {
            throw new ChartSpecException('Unknown measure.');
        }
        if ($fn === 'count') {
            return ['fn' => 'count', 'column' => null, 'src' => 'a'];
        }
        $src = $this->src($m);
        $this->col(isset($m['column']) ? $m['column'] : null, $src);
        if (in_array($fn, ['sum', 'avg'], true) && !$this->isNumeric($m['column'], $src)) {
            throw new ChartSpecException("Sum and average need a numeric field; '{$m['column']}' is text.");
        }
        return ['fn' => $fn, 'column' => $m['column'], 'src' => $src];
    }

    private function normalizeFilter($f)
    {
        if (!is_array($f)) {
            throw new ChartSpecException('Bad filter.');
        }
        $src = $this->src($f);
        $this->col(isset($f['column']) ? $f['column'] : null, $src);
        $op = isset($f['op']) ? $f['op'] : 'eq';
        if (!in_array($op, self::OPS, true)) {
            throw new ChartSpecException('Unknown filter operator.');
        }
        $numeric = $this->isNumeric($f['column'], $src);
        $value = isset($f['value']) ? $f['value'] : null;
        $isInt = function ($v) {
            return is_scalar($v) && preg_match('/^-?\d{1,18}$/', trim((string) $v)) === 1;
        };

        switch ($op) {
            case 'blank':
            case 'notblank':
                $value = null;
                break;
            case 'in':
                if (is_string($value)) {
                    $value = array_map('trim', explode(',', $value));
                }
                if (!is_array($value) || count($value) === 0 || count($value) > $this->cfg['max_in_values']) {
                    throw new ChartSpecException('"is one of" needs between 1 and ' . $this->cfg['max_in_values'] . ' values.');
                }
                foreach ($value as $v) {
                    if (!is_scalar($v) || strlen((string) $v) > 200 || ($numeric && !$isInt($v))) {
                        throw new ChartSpecException("Bad value for '{$f['column']}'.");
                    }
                }
                $value = array_values(array_map('strval', $value));
                break;
            case 'between':
                if (is_string($value)) {
                    $value = array_map('trim', explode(',', $value));
                }
                if (!is_array($value) || count($value) !== 2 || !$isInt($value[0]) || !$isInt($value[1])) {
                    throw new ChartSpecException('"between" needs two whole numbers (dates as YYYYMMDD).');
                }
                $value = [(string) (int) $value[0], (string) (int) $value[1]];
                break;
            case 'gte':
            case 'lte':
                if (!$isInt($value)) {
                    throw new ChartSpecException('This filter needs a whole number (dates as YYYYMMDD).');
                }
                $value = (string) (int) $value;
                break;
            default: // eq, neq, prefix
                if (!is_scalar($value) || strlen((string) $value) > 200 || (string) $value === '') {
                    throw new ChartSpecException("Enter a value for '{$f['column']}'.");
                }
                if ($numeric && $op !== 'prefix' && !$isInt($value)) {
                    throw new ChartSpecException("'{$f['column']}' is numeric; enter a whole number.");
                }
                $value = (string) $value;
        }
        return ['column' => $f['column'], 'op' => $op, 'value' => $value, 'src' => $src];
    }

    // ------------------------------------------------------------------
    // SQL building
    // ------------------------------------------------------------------

    /** String form of a column with blanks labelled. */
    private function textExpr($name, $src = 'a')
    {
        $c = $this->col($name, $src);
        return "COALESCE(NULLIF(TRIM(CAST({$c} AS CHAR)), ''), '" . self::BLANK . "')";
    }

    private function dateNum($name, $src = 'a')
    {
        return 'CAST(' . $this->col($name, $src) . ' AS UNSIGNED)';
    }

    /** SQL expression for the X value of a normalized spec. */
    private function xExpr(array $x)
    {
        switch ($x['kind']) {
            case 'time':
                $d = $this->dateNum($x['column'], $x['src']);
                return $x['bucket'] === 'year' ? "FLOOR({$d} / 10000)" : "FLOOR({$d} / 100)";
            case 'bin':
                $c = $this->col($x['column'], $x['src']);
                return $x['bin'] > 1 ? "(FLOOR({$c} / {$x['bin']}) * {$x['bin']})" : $c;
            case 'days_between':
                $d = $this->daysExpr($x);
                $e = "LEAST({$d}, {$x['cap']})";
                return $x['bin'] > 1 ? "(FLOOR({$e} / {$x['bin']}) * {$x['bin']})" : $e;
            default:
                return $this->textExpr($x['column'], $x['src']);
        }
    }

    private function daysExpr(array $x)
    {
        $from = $this->col($x['from'], $x['from_src']);
        $to = $this->col($x['to'], $x['to_src']);
        return "DATEDIFF(STR_TO_DATE(CAST({$to} AS CHAR), '%Y%m%d'), STR_TO_DATE(CAST({$from} AS CHAR), '%Y%m%d'))";
    }

    /** FROM clause: one table, or the two joined on their ID columns. */
    private function fromClause(array $spec)
    {
        $a = '`' . $this->tables['a'] . '`';
        if (!$spec['join']) {
            return " FROM {$a}";
        }
        $b = '`' . $this->tables['b'] . '`';
        return " FROM {$a} a JOIN {$b} b ON " . $this->col($spec['join']['left'], 'a') . ' = ' . $this->col($spec['join']['right'], 'b');
    }

    /** Conditions every query gets so that blank / junk values don't become a bucket or a join match. */
    private function implicitWhere(array $spec)
    {
        $where = [];
        if ($spec['join']) {
            // blank keys must never match each other (many tables leave an ID blank)
            foreach ([['left', 'a'], ['right', 'b']] as $k) {
                $c = $this->col($spec['join'][$k[0]], $k[1]);
                $where[] = "({$c} IS NOT NULL AND TRIM(CAST({$c} AS CHAR)) <> '')";
            }
        }
        $x = $spec['x'];
        if ($x['kind'] === 'time') {
            $where[] = $this->dateNum($x['column'], $x['src']) . ' BETWEEN 19000101 AND 20991231';
        } elseif ($x['kind'] === 'days_between') {
            $d = $this->daysExpr($x);
            $where[] = "{$d} IS NOT NULL";
            $where[] = "{$d} >= 0";
        } elseif ($x['kind'] === 'bin') {
            $where[] = $this->col($x['column'], $x['src']) . ' IS NOT NULL';
        }
        return $where;
    }

    private function filterWhere(array $filters, array &$bind)
    {
        $out = [];
        foreach ($filters as $f) {
            $c = $this->col($f['column'], $f['src']);
            switch ($f['op']) {
                case 'eq':
                case 'neq':
                    $out[] = $c . ($f['op'] === 'eq' ? ' = ?' : ' <> ?');
                    $bind[] = $f['value'];
                    break;
                case 'in':
                    $out[] = $c . ' IN (' . implode(',', array_fill(0, count($f['value']), '?')) . ')';
                    foreach ($f['value'] as $v) {
                        $bind[] = $v;
                    }
                    break;
                case 'gte':
                    $out[] = "CAST({$c} AS SIGNED) >= ?";
                    $bind[] = $f['value'];
                    break;
                case 'lte':
                    $out[] = "CAST({$c} AS SIGNED) <= ?";
                    $bind[] = $f['value'];
                    break;
                case 'between':
                    $out[] = "CAST({$c} AS SIGNED) BETWEEN ? AND ?";
                    $bind[] = $f['value'][0];
                    $bind[] = $f['value'][1];
                    break;
                case 'prefix':
                    $out[] = "CAST({$c} AS CHAR) LIKE ?";
                    $bind[] = addcslashes($f['value'], '%_\\') . '%';
                    break;
                case 'blank':
                    $out[] = "({$c} IS NULL OR TRIM(CAST({$c} AS CHAR)) = '')";
                    break;
                case 'notblank':
                    $out[] = "({$c} IS NOT NULL AND TRIM(CAST({$c} AS CHAR)) <> '')";
                    break;
            }
        }
        return $out;
    }

    private function measureExpr(array $m)
    {
        switch ($m['fn']) {
            case 'count_distinct':
                return 'COUNT(DISTINCT ' . $this->col($m['column'], $m['src']) . ')';
            case 'sum':
                return 'SUM(' . $this->col($m['column'], $m['src']) . ')';
            case 'avg':
                return 'AVG(' . $this->col($m['column'], $m['src']) . ')';
            default:
                return 'COUNT(*)';
        }
    }

    private function hint()
    {
        return '/*+ MAX_EXECUTION_TIME(' . (int) $this->cfg['timeout_ms'] . ') */ ';
    }

    private function whereClause(array $spec, array &$bind, array $extra = [])
    {
        $where = $this->implicitWhere($spec);
        $where = array_merge($where, $this->filterWhere($spec['filters'], $bind), $extra);
        return $where ? ' WHERE ' . implode(' AND ', $where) : '';
    }

    /**
     * Which dimension (if any) needs a "top N values" lookup first: the group
     * field of line/stacked/heatmap charts, and the category X of
     * stacked/heatmap charts.
     *
     * @return string[] subset of ['group', 'x']
     */
    public function lookups(array $spec)
    {
        $need = [];
        if ($spec['group']) {
            $need[] = 'group';
        }
        if ($spec['x']['kind'] === 'category' && in_array($spec['chart'], ['stacked', 'heatmap'], true)) {
            $need[] = 'x';
        }
        return $need;
    }

    /**
     * Query returning the most frequent values of a dimension, so the main
     * query can fold the rest into "Other" (group) or drop them (x).
     *
     * @return array ['sql' => string, 'bindings' => array]
     */
    public function topValuesQuery(array $spec, $which)
    {
        $bind = [];
        $ref = $which === 'group' ? $spec['group'] : $spec['x'];
        $expr = $this->textExpr($ref['column'], $ref['src']);
        $limit = $which === 'group' ? $this->cfg['max_series'] : $spec['limit'];
        $sql = $this->hint() . "SELECT {$expr} AS v" . $this->fromClause($spec) . $this->whereClause($spec, $bind)
             . ' GROUP BY 1 ORDER BY COUNT(*) DESC, 1 ASC LIMIT ' . (int) $limit;
        return ['sql' => $sql, 'bindings' => $bind];
    }

    /**
     * The main aggregate query.
     *
     * @param array $spec    normalized spec
     * @param array $tops    ['group' => [values], 'x' => [values]] from topValuesQuery()
     * @return array ['sql' => string, 'bindings' => array]
     */
    public function query(array $spec, array $tops = [])
    {
        $selectBind = [];
        $whereBind = [];
        $extra = [];
        $extraBind = [];

        $xExpr = $this->xExpr($spec['x']);
        $select = ["{$xExpr} AS x"];

        if ($spec['group']) {
            $gt = $this->textExpr($spec['group']['column'], $spec['group']['src']);
            $values = isset($tops['group']) ? $tops['group'] : [];
            if (!$values) {
                $select[] = "'" . self::OTHER . "' AS g";
            } else {
                $in = implode(',', array_fill(0, count($values), '?'));
                $select[] = "CASE WHEN {$gt} IN ({$in}) THEN {$gt} ELSE '" . self::OTHER . "' END AS g";
                $selectBind = array_values($values);
            }
        }
        if ($spec['x']['kind'] === 'category' && !empty($tops['x'])) {
            $values = $tops['x'];
            $extra[] = $this->textExpr($spec['x']['column'], $spec['x']['src']) . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
            $extraBind = array_values($values);
        }

        $select[] = $this->measureExpr($spec['measure']) . ' AS v';
        $select[] = 'COUNT(*) AS n';

        $where = $this->implicitWhere($spec);
        $where = array_merge($where, $this->filterWhere($spec['filters'], $whereBind));
        foreach ($extra as $e) {
            $where[] = $e;
        }
        $bind = array_merge($selectBind, $whereBind, $extraBind);

        $group = $spec['group'] ? 'GROUP BY 1, 2' : 'GROUP BY 1';
        $x = $spec['x'];
        if (in_array($x['kind'], ['time', 'bin', 'days_between'], true)) {
            $order = 'ORDER BY 1 ASC';
            $limit = $this->cfg['max_buckets'] * ($spec['group'] ? $this->cfg['max_series'] + 1 : 1);
        } elseif ($spec['chart'] === 'bar' || $spec['chart'] === 'hbar') {
            $order = 'ORDER BY v DESC, 1 ASC';
            $limit = $spec['limit'];
        } else {
            $order = 'ORDER BY 1 ASC';
            $limit = $spec['limit'] * ($spec['group'] ? $this->cfg['max_series'] + 1 : 1);
        }

        $sql = $this->hint() . 'SELECT ' . implode(', ', $select) . $this->fromClause($spec)
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . " {$group} {$order} LIMIT " . (int) $limit;

        return ['sql' => $sql, 'bindings' => $bind];
    }

    // ------------------------------------------------------------------
    // Result shaping
    // ------------------------------------------------------------------

    /**
     * Pivot result rows (x, [g], v, n) into the structure the render service
     * draws, apply small-cell suppression and order categories.
     *
     * @param array $rows rows as arrays or objects with x, g (optional), v, n
     * @return array
     */
    public function shape(array $rows, array $spec)
    {
        $min = (int) $this->cfg['min_cell_size'];
        $isTime = $spec['x']['kind'] === 'time';
        $isNum = in_array($spec['x']['kind'], ['bin', 'days_between'], true);
        $hasGroup = (bool) $spec['group'];
        $fill = in_array($spec['measure']['fn'], ['count', 'count_distinct', 'sum'], true) ? 0 : null;

        $cells = [];      // [seriesName][xKey] => value|null (suppressed)
        $xKeys = [];
        $totals = [];     // series totals / x totals for ordering
        $xTotals = [];
        $suppressed = 0;

        foreach ($rows as $row) {
            $row = (array) $row;
            $xk = (string) $row['x'];
            $s = $hasGroup ? (string) $row['g'] : '__single';
            $n = (int) $row['n'];
            $xKeys[$xk] = true;
            if ($min > 1 && $n < $min) {
                $cells[$s][$xk] = null;
                $suppressed++;
                continue;
            }
            $v = $row['v'] === null ? null : $row['v'] + 0;
            $cells[$s][$xk] = $v;
            $totals[$s] = (isset($totals[$s]) ? $totals[$s] : 0) + $n;
            $xTotals[$xk] = (isset($xTotals[$xk]) ? $xTotals[$xk] : 0) + $n;
        }

        // x ordering
        $keys = array_keys($xKeys);
        if ($isTime || $isNum) {
            usort($keys, function ($a, $b) {
                return (float) $a <=> (float) $b;
            });
            if ($isTime) {
                $keys = $this->fillBuckets($keys, $spec['x']['bucket']);
            } elseif ($spec['x']['bin'] > 0 && count($keys) > 1) {
                $keys = $this->fillBins($keys, (int) $spec['x']['bin']);
            }
        } else {
            usort($keys, function ($a, $b) use ($xTotals) {
                $ta = isset($xTotals[$a]) ? $xTotals[$a] : 0;
                $tb = isset($xTotals[$b]) ? $xTotals[$b] : 0;
                return $tb <=> $ta ?: strcmp($a, $b);
            });
        }

        // series ordering: biggest first, Other last
        $names = array_keys($cells);
        usort($names, function ($a, $b) use ($totals) {
            if ($a === self::OTHER) {
                return 1;
            }
            if ($b === self::OTHER) {
                return -1;
            }
            $ta = isset($totals[$a]) ? $totals[$a] : 0;
            $tb = isset($totals[$b]) ? $totals[$b] : 0;
            return $tb <=> $ta ?: strcmp($a, $b);
        });

        $series = [];
        foreach ($names as $s) {
            $vals = [];
            foreach ($keys as $k) {
                if (array_key_exists($k, $cells[$s])) {
                    $vals[] = $cells[$s][$k];          // value, or null when suppressed
                } else {
                    $vals[] = $fill;                   // no rows at all
                }
            }
            $series[] = ['name' => $s === '__single' ? $this->measureLabel($spec) : $s, 'values' => $vals];
        }

        return [
            'chart'         => $spec['chart'],
            'title'         => $spec['title'] !== '' ? $spec['title'] : $this->defaultTitle($spec),
            'x_kind'        => $isTime ? 'time' : ($isNum ? 'number' : 'category'),
            'bucket'        => $isTime ? $spec['x']['bucket'] : null,
            'x'             => $keys,
            'x_label'       => $this->xLabel($spec),
            'y_label'       => $this->measureLabel($spec),
            'series'        => $series,
            'suppressed'    => $suppressed,
            'min_cell_size' => $min,
        ];
    }

    /** Insert missing months / years between the first and last bucket. */
    private function fillBuckets(array $keys, $bucket)
    {
        if (count($keys) < 2) {
            return $keys;
        }
        $first = (int) $keys[0];
        $last = (int) $keys[count($keys) - 1];
        $out = [];
        if ($bucket === 'year') {
            if ($last - $first > $this->cfg['max_buckets']) {
                return $keys;
            }
            for ($y = $first; $y <= $last; $y++) {
                $out[] = (string) $y;
            }
            return $out;
        }
        $y = intdiv($first, 100);
        $m = $first % 100;
        $guard = 0;
        while (($y * 100 + $m) <= $last && $guard++ <= $this->cfg['max_buckets']) {
            $out[] = (string) ($y * 100 + $m);
            if (++$m > 12) {
                $m = 1;
                $y++;
            }
        }
        return $out;
    }

    private function fillBins(array $keys, $bin)
    {
        $first = (int) $keys[0];
        $last = (int) $keys[count($keys) - 1];
        if (($last - $first) / $bin > $this->cfg['max_buckets']) {
            return $keys;
        }
        $out = [];
        for ($b = $first; $b <= $last; $b += $bin) {
            $out[] = (string) $b;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Labels
    // ------------------------------------------------------------------

    public static function pretty($column)
    {
        return ucwords(str_replace(['_', '-'], ' ', (string) $column));
    }

    private function measureLabel(array $spec)
    {
        $m = $spec['measure'];
        switch ($m['fn']) {
            case 'count_distinct':
                return 'Distinct ' . self::pretty($m['column']);
            case 'sum':
                return 'Sum of ' . self::pretty($m['column']);
            case 'avg':
                return 'Average ' . self::pretty($m['column']);
            default:
                return 'Records';
        }
    }

    private function xLabel(array $spec)
    {
        $x = $spec['x'];
        if ($x['kind'] === 'days_between') {
            return 'Days from ' . self::pretty($x['from']) . ' to ' . self::pretty($x['to']);
        }
        if ($x['kind'] === 'time') {
            return self::pretty($x['column']) . ' (' . $x['bucket'] . ')';
        }
        return self::pretty($x['column']);
    }

    private function defaultTitle(array $spec)
    {
        $by = $this->xLabel($spec);
        $t = $this->measureLabel($spec) . ' by ' . $by;
        if ($spec['group']) {
            $t .= ', split by ' . self::pretty($spec['group']['column']);
        }
        return $t;
    }
}
