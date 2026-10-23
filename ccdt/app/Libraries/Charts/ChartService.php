<?php

namespace App\Libraries\Charts;

use App\Helpers\TableIndexHelper;
use App\Models\ChartSummary;
use App\Models\Table;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs a chart spec for an imported table (optionally combined with a second
 * table of the same collection): validates it (ChartQueryBuilder), runs the
 * aggregate query on the read-only chart connection, and has the matplotlib
 * service draw the result. Results are cached per spec and table version.
 */
class ChartService
{
    private $client;
    private $serverChecked = [];

    public function __construct(ChartRenderClient $client = null)
    {
        $this->client = $client ?: new ChartRenderClient(
            config('charts.service_url'),
            config('charts.service_token'),
            config('charts.service_timeout')
        );
    }

    private function db()
    {
        return DB::connection(config('charts.connection'));
    }

    private function quote($name)
    {
        return str_replace('`', '', $name);
    }

    /**
     * Column name => MySQL type for the table.
     */
    public function columnsFor(Table $table)
    {
        $out = [];
        foreach ($this->db()->select('DESCRIBE `' . $this->quote($table->tblNme) . '`') as $c) {
            $out[$c->Field] = $c->Type;
        }
        return $out;
    }

    /**
     * Leading columns of the table's indexes, on the chart connection.
     */
    public function indexedColumns(Table $table)
    {
        return (new TableIndexHelper)->indexedColumns($table->tblNme, config('charts.connection'));
    }

    /**
     * Another table that may be combined with $main in a chart: same
     * collection, not disabled, a different table.
     *
     * @throws ChartSpecException
     */
    public function joinTable(Table $main, $otherId)
    {
        $other = Table::find($otherId);
        if (!$other || (int) $other->id === (int) $main->id || !$other->hasAccess
            || (int) $other->collection_id !== (int) $main->collection_id) {
            throw new ChartSpecException('That table cannot be combined with this one.');
        }
        return $other;
    }

    /**
     * Tables that can be combined with $main (same collection, enabled).
     */
    public function joinableTables(Table $main)
    {
        return Table::where('collection_id', $main->collection_id)
            ->where('hasAccess', true)->where('id', '<>', $main->id)
            ->orderBy('tblNme')->get(['id', 'tblNme']);
    }

    /**
     * Builder for the table, with a second table when the spec asks for a join.
     *
     * @param Table      $table main table
     * @param array|null $join  spec['join'] (table_id, left, right) or null
     * @throws ChartSpecException when the second table is not allowed or the ID columns are not indexed
     */
    public function builder(Table $table, array $join = null)
    {
        $cfg = config('charts');
        if (!$join) {
            return new ChartQueryBuilder($table->tblNme, $this->columnsFor($table), $cfg);
        }

        $other = $this->joinTable($table, isset($join['table_id']) ? $join['table_id'] : null);
        $builder = new ChartQueryBuilder($table->tblNme, $this->columnsFor($table), $cfg,
            ['table' => $other->tblNme, 'columns' => $this->columnsFor($other)]);

        // an unindexed join on millions of rows never finishes: refuse it up front
        $join = array_merge($join, $builder->checkJoin($join));   // validates the key columns
        $missing = [];
        if (!in_array($join['left'], $this->indexedColumns($table), true)) {
            $missing[] = $table->tblNme . '.' . $join['left'];
        }
        if (!in_array($join['right'], $this->indexedColumns($other), true)) {
            $missing[] = $other->tblNme . '.' . $join['right'];
        }
        if ($missing) {
            throw new ChartSpecException('These fields are not indexed yet, so the tables cannot be combined: ' . implode(', ', $missing)
                . '. Ask an administrator to run "php artisan table:create:index --all".');
        }
        return $builder;
    }

    /**
     * Describe a table for the builder page: usable columns, starter charts and
     * the tables it can be combined with.
     */
    public function describe(Table $table)
    {
        $builder = $this->builder($table);
        $columns = $builder->usableColumns();
        return [
            'columns'  => $columns,
            'starters' => StarterCharts::forColumns(array_keys($columns)),
            'joinable' => $this->joinableTables($table)->map(function ($t) {
                return ['id' => $t->id, 'name' => $t->tblNme];
            })->values()->all(),
        ];
    }

    /**
     * Describe the second table of a combination: its usable columns, the ID
     * fields the two tables share (and whether both sides are indexed), and
     * starter charts for the pair.
     */
    public function describeJoin(Table $main, Table $other)
    {
        $this->joinTable($main, $other->id);
        $a = new ChartQueryBuilder($main->tblNme, $this->columnsFor($main), config('charts'),
            ['table' => $other->tblNme, 'columns' => $this->columnsFor($other)]);
        $colsA = $a->usableColumns('a');
        $colsB = $a->usableColumns('b');
        $idxA = $this->indexedColumns($main);
        $idxB = $this->indexedColumns($other);

        $keys = [];
        foreach ($colsA as $name => $info) {
            if (!empty($info['id']) && isset($colsB[$name]) && !empty($colsB[$name]['id'])) {
                $keys[] = ['left' => $name, 'right' => $name,
                           'indexed' => in_array($name, $idxA, true) && in_array($name, $idxB, true)];
            }
        }
        $starters = StarterCharts::forJoin(array_keys($colsA), array_keys($colsB));
        foreach ($starters as &$s) {
            $s['spec']['join']['table_id'] = $other->id;
        }
        return ['columns' => $colsB, 'keys' => $keys, 'starters' => $starters];
    }

    /**
     * Key of a chart's cached and stored result: the spec, the tables it uses
     * and their updated_at (moved by every import), and the small-cell limit.
     */
    public function cacheKey(Table $table, $join, array $norm)
    {
        $other = $join ? Table::find($join['table_id']) : null;
        return md5($table->tblNme . '|' . $table->updated_at . '|'
            . ($other ? $other->tblNme . '|' . $other->updated_at : '')
            . '|' . json_encode($norm) . '|' . config('charts.min_cell_size'));
    }

    /**
     * Validate, query and draw.
     *
     * @return array [shaped, png (base64), query_ms, render_ms, cached, summary]
     * @throws ChartSpecException   bad spec (message is user-safe)
     * @throws \RuntimeException    render service problem
     */
    public function run(Table $table, array $spec)
    {
        $join = isset($spec['join']) && is_array($spec['join']) && !empty($spec['join']) ? $spec['join'] : null;
        $builder = $this->builder($table, $join);
        $norm = $builder->normalize($spec);
        $key = 'chart:' . $this->cacheKey($table, $join, $norm);

        $hit = Cache::get($key);
        if ($hit !== null) {
            $hit['cached'] = true;
            return $hit;
        }

        list($shaped, $queryMs, $fromSummary) = $this->shapedResult($table, $join, $builder, $norm, false);

        if (count($shaped['x']) === 0) {
            throw new ChartSpecException('No records match this chart (or every group was smaller than the minimum cell size of '
                . (int) config('charts.min_cell_size') . ').');
        }

        $t1 = microtime(true);
        $png = $this->client->render($shaped);
        $renderMs = (int) round((microtime(true) - $t1) * 1000);

        $result = [
            'shaped'    => $shaped,
            'png'       => base64_encode($png),
            'query_ms'  => $queryMs,
            'render_ms' => $renderMs,
            'cached'    => false,
            'summary'   => $fromSummary,
        ];
        Cache::put($key, $result, now()->addMinutes((int) config('charts.cache_minutes')));
        return $result;
    }

    /**
     * The aggregated result of a chart: from its stored summary when there is
     * one, otherwise by querying. A query that took summary_min_ms or longer
     * (or any query when $store) is stored for next time.
     *
     * @return array [shaped, query_ms, fromSummary]
     */
    private function shapedResult(Table $table, $join, ChartQueryBuilder $builder, array $norm, $store)
    {
        $key = $this->cacheKey($table, $join, $norm);
        if (!$store) {
            $row = ChartSummary::where('cache_key', $key)->first();
            if ($row) {
                $shaped = json_decode($row->shaped, true);
                if (is_array($shaped) && isset($shaped['x'])) {
                    return [$shaped, 0, true];
                }
            }
        }

        list($shaped, $queryMs) = $this->aggregate($builder, $norm);

        if (count($shaped['x']) > 0 && ($store || $queryMs >= (int) config('charts.summary_min_ms'))) {
            try {
                ChartSummary::updateOrCreate(['cache_key' => $key], [
                    'table_id'       => $table->id,
                    'other_table_id' => $join ? (int) $join['table_id'] : null,
                    'spec'           => json_encode($norm),
                    'shaped'         => json_encode($shaped),
                    'query_ms'       => $queryMs,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Could not store a chart summary: ' . $e->getMessage());   // the chart still works
            }
        }
        return [$shaped, $queryMs, false];
    }

    /**
     * Run a validated spec on the read-only connection and shape the result
     * (small groups hidden), without drawing it or storing it.
     *
     * @param ChartQueryBuilder $builder
     * @param array             $norm    builder->normalize() output
     * @return array [shaped, query_ms]
     */
    public function aggregate(ChartQueryBuilder $builder, array $norm)
    {
        $t0 = microtime(true);
        $this->prepareConnection();
        $tops = [];
        foreach ($builder->lookups($norm) as $which) {
            $q = $builder->topValuesQuery($norm, $which);
            $tops[$which] = array_map(function ($r) {
                return ((array) $r)['v'];
            }, $this->db()->select($q['sql'], $q['bindings']));
        }
        $q = $builder->query($norm, $tops);
        $rows = $this->db()->select($q['sql'], $q['bindings']);
        $shaped = $builder->shape($rows, $norm);
        return [$shaped, (int) round((microtime(true) - $t0) * 1000)];
    }

    /**
     * A read-only SELECT on the chart connection with the statement time limit
     * applied. For the data dashboard's counts: the SQL is built from names that
     * came from the table's own DESCRIBE, never from user input.
     *
     * @return array rows as objects
     */
    public function select($sql, array $bindings = [])
    {
        $this->prepareConnection();
        return $this->db()->select($sql, $bindings);
    }

    /**
     * Rebuild the stored results of the ready-made charts for a table: its own
     * and those combining it with each other table of its collection that
     * shares the ID fields (the table as the main table). Older summaries of
     * the table are dropped first. A chart that cannot be built (no matching
     * fields, ID not indexed, query too slow) is skipped.
     *
     * @return array ['built' => n, 'skipped' => n]
     */
    public function refreshSummaries(Table $table)
    {
        ChartSummary::where('table_id', $table->id)->orWhere('other_table_id', $table->id)->delete();

        $jobs = [];   // [join or null, spec]
        foreach (StarterCharts::forColumns(array_keys($this->builder($table)->usableColumns())) as $s) {
            $jobs[] = [null, $s['spec']];
        }
        foreach ($this->joinableTables($table) as $o) {
            try {
                foreach ($this->describeJoin($table, Table::find($o->id))['starters'] as $s) {
                    $jobs[] = [$s['spec']['join'], $s['spec']];
                }
            } catch (\Throwable $e) {
                Log::info("Chart summaries: {$table->tblNme} with {$o->tblNme}: " . $e->getMessage());
            }
        }

        $built = 0;
        $skipped = 0;
        foreach ($jobs as $job) {
            try {
                $builder = $this->builder($table, $job[0]);
                $norm = $builder->normalize($job[1]);
                $this->shapedResult($table, $job[0], $builder, $norm, true);
                $built++;
            } catch (\Throwable $e) {
                $skipped++;
                Log::info("Chart summaries: skipped a chart of {$table->tblNme}: " . $e->getMessage());
            }
        }
        return ['built' => $built, 'skipped' => $skipped];
    }

    /**
     * MySQL 5.7 honors the MAX_EXECUTION_TIME hint the builder adds; MariaDB
     * ignores it and needs max_statement_time (seconds) on the session.
     */
    private function prepareConnection()
    {
        $name = config('charts.connection');
        if (isset($this->serverChecked[$name])) {
            return;
        }
        $this->serverChecked[$name] = true;
        $version = $this->db()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        if (stripos($version, 'mariadb') !== false) {
            $this->db()->statement('SET SESSION max_statement_time = ' . (int) ceil(config('charts.timeout_ms') / 1000));
        }
    }
}
