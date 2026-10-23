<?php

namespace App\Libraries\Dashboard;

use App\Libraries\Charts\ChartService;
use App\Models\DashboardSnapshot;
use App\Models\Table;
use Illuminate\Support\Facades\Log;

/**
 * Counts what is in a table for the data dashboard and stores the result.
 *
 * Everything goes through ChartService / ChartQueryBuilder on the read-only
 * chart connection: only columns the chart builder allows are used (no names,
 * contact details or free text), groups smaller than the minimum cell size are
 * hidden, and each query has the same time limit. What is stored is aggregated
 * numbers only, never records.
 *
 * Nothing here runs when someone opens the page: counting happens after an
 * import (queue "low") or with  php artisan dashboard:refresh.
 */
class DashboardService
{
    const VERSION = 1;

    private $charts;
    private $planner;

    public function __construct(ChartService $charts = null, DashboardPlanner $planner = null)
    {
        $this->charts = $charts ?: new ChartService();
        $this->planner = $planner ?: new DashboardPlanner((array) config('charts.dashboard'));
    }

    private static function q($name)
    {
        return '`' . str_replace('`', '', $name) . '`';
    }

    /**
     * Count one table.
     *
     * @return array the payload (see docs/dashboard.md)
     */
    public function build(Table $table)
    {
        $started = microtime(true);
        $budget = (int) config('charts.dashboard_budget_seconds');
        $over = function () use ($started, $budget) {
            return $budget > 0 && (microtime(true) - $started) > $budget;
        };

        $builder = $this->charts->builder($table);
        $usable = $builder->usableColumns();
        $t = self::q($table->tblNme);

        $rows = (int) $this->charts->select("SELECT COUNT(*) AS n FROM {$t}")[0]->n;
        $payload = [
            'version'       => self::VERSION,
            'table'         => ['id' => $table->id, 'name' => $table->tblNme],
            'rows'          => $rows,
            'ids'           => [],
            'breakdowns'    => [],
            'trends'        => [],
            'partial'       => false,
            'skipped'       => [],
            'min_cell_size' => (int) config('charts.min_cell_size'),
            'seconds'       => 0,
        ];
        if ($rows === 0) {
            $payload['seconds'] = round(microtime(true) - $started, 1);
            return $payload;
        }

        $cand = $this->planner->candidates($usable);

        // 1) distinct values of the ID columns (each can use its own index)
        foreach ($cand['ids'] as $col) {
            if ($over()) {
                $payload['partial'] = true;
                break;
            }
            try {
                // plain COUNT(DISTINCT) can use the column's index; a blank text value is counted once, so take it off
                $c = self::q($col);
                if (!empty($usable[$col]['numeric'])) {
                    $n = (int) $this->charts->select("SELECT COUNT(DISTINCT {$c}) AS n FROM {$t}")[0]->n;
                } else {
                    $r = $this->charts->select("SELECT COUNT(DISTINCT {$c}) AS n, EXISTS(SELECT 1 FROM {$t} WHERE {$c} = '' LIMIT 1) AS b FROM {$t}")[0];
                    $n = max(0, (int) $r->n - (int) $r->b);
                }
                $payload['ids'][] = ['column' => $col, 'label' => DashboardPlanner::label($col), 'count' => $n];
            } catch (\Throwable $e) {
                $this->skip($payload, $col, $e);
            }
        }

        // 2) how many distinct values the other columns have, in a sample of the rows
        $distinct = [];
        $sampled = min($rows, (int) $this->planner->config('sample_rows'));
        if ($cand['categorical']) {
            try {
                $cols = [];
                $sel = [];
                foreach (array_values($cand['categorical']) as $i => $col) {
                    $cols[] = self::q($col);
                    $sel[] = 'COUNT(DISTINCT ' . self::q($col) . ") AS d{$i}";
                }
                $r = (array) $this->charts->select('SELECT ' . implode(', ', $sel) . ' FROM (SELECT ' . implode(', ', $cols)
                    . " FROM {$t} LIMIT " . (int) $sampled . ') s')[0];
                foreach (array_values($cand['categorical']) as $i => $col) {
                    $distinct[$col] = (int) $r["d{$i}"];
                }
            } catch (\Throwable $e) {
                $this->skip($payload, '(distinct values)', $e);
            }
        }

        // 3) date range of the date columns (one pass)
        $ranges = [];
        if ($cand['dates']) {
            try {
                $sel = [];
                foreach ($cand['dates'] as $i => $col) {
                    $v = 'CAST(' . self::q($col) . ' AS UNSIGNED)';
                    $ok = "CASE WHEN {$v} BETWEEN 19000101 AND 20991231 THEN {$v} END";
                    $sel[] = "MIN({$ok}) AS mn{$i}, MAX({$ok}) AS mx{$i}";
                }
                $r = (array) $this->charts->select('SELECT ' . implode(', ', $sel) . " FROM {$t}")[0];
                foreach ($cand['dates'] as $i => $col) {
                    $ranges[$col] = $r["mn{$i}"] === null ? null : [(int) $r["mn{$i}"], (int) $r["mx{$i}"]];
                }
            } catch (\Throwable $e) {
                $this->skip($payload, '(date ranges)', $e);
            }
        }

        $plan = $this->planner->plan($cand, $distinct, $sampled, $ranges);

        // 4) the counts, through the chart builder
        foreach ($plan['breakdowns'] as $b) {
            if ($over()) {
                $payload['partial'] = true;
                break;
            }
            $shaped = $this->run($builder, $this->planner->breakdownSpec($b['column']), $payload, $b['column']);
            if ($shaped && count($shaped['x']) > 0) {
                $payload['breakdowns'][] = ['column' => $b['column'], 'label' => DashboardPlanner::label($b['column']),
                                            'group' => $b['group'], 'shaped' => $shaped];
            }
        }
        foreach ($plan['trends'] as $tr) {
            if ($over()) {
                $payload['partial'] = true;
                break;
            }
            $shaped = $this->run($builder, $this->planner->trendSpec($tr['column'], $tr['bucket']), $payload, $tr['column']);
            if ($shaped && count($shaped['x']) > 0) {
                $payload['trends'][] = ['column' => $tr['column'], 'label' => DashboardPlanner::label($tr['column']),
                                        'bucket' => $tr['bucket'], 'shaped' => $shaped];
            }
        }

        $payload['seconds'] = round(microtime(true) - $started, 1);
        return $payload;
    }

    private function run($builder, array $spec, array &$payload, $col)
    {
        try {
            list($shaped) = $this->charts->aggregate($builder, $builder->normalize($spec));
            return $shaped;
        } catch (\Throwable $e) {
            $this->skip($payload, $col, $e);
            return null;
        }
    }

    private function skip(array &$payload, $what, \Throwable $e)
    {
        $payload['skipped'][] = $what;
        Log::info("Dashboard: {$payload['table']['name']}: skipped {$what}: " . $e->getMessage());
    }

    /**
     * Count a table and store the result.
     */
    public function refresh(Table $table)
    {
        $payload = $this->build($table);
        return DashboardSnapshot::updateOrCreate(['table_id' => $table->id], [
            'table_updated_at' => $table->updated_at,
            'row_count'        => $payload['rows'],
            'summary'          => json_encode(DashboardPlanner::summaryOf($payload)),
            'payload'          => json_encode($payload),
            'seconds'          => (int) ceil($payload['seconds']),
            'computed_at'      => now(),
        ]);
    }
}
