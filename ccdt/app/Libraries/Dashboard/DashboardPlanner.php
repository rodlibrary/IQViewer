<?php

namespace App\Libraries\Dashboard;

use App\Libraries\Charts\ChartQueryBuilder;

/**
 * Decides what the data dashboard counts for a table, and sums the results up.
 * Pure PHP (no Laravel, no database): the service feeds it the table's columns
 * and a few cheap statistics, and gets back a plan of chart specs to run.
 *
 * What a table gets:
 *   ids          columns that are IDs (person, address, communication...): the
 *                number of distinct values
 *   breakdowns   short-text / code columns: the most common values. "distribution"
 *                for who and where (gender, city, state, zip...), "code" for
 *                types, statuses, staff, topics..., "other" for any remaining
 *                column with few distinct values
 *   trends       date columns (YYYYMMDD): records per year or month
 *
 * Which columns may be used at all is decided by ChartQueryBuilder (names,
 * contact details, free text and other personal columns are never offered), and
 * the counts are run through the same builder, so groups smaller than the
 * minimum cell size are hidden the same way they are in the chart builder.
 *
 * Written to run on PHP 7.3 like the rest of the chart code.
 */
class DashboardPlanner
{
    /** Name words (split on "_") of who/where fields. */
    const DISTRIBUTION_WORDS = ['gender', 'sex', 'race', 'ethnicity', 'ethnic', 'marital', 'party', 'religion', 'language', 'age',
        'city', 'state', 'county', 'country', 'zip', 'postal', 'district', 'precinct', 'region', 'ward', 'congressional'];

    /** Name words of type / status / code fields. */
    const CODE_WORDS = ['type', 'status', 'code', 'flag', 'category', 'class', 'source', 'method', 'medium', 'priority', 'staff',
        'position', 'topic', 'issue', 'response', 'agency', 'action', 'outcome', 'stage', 'reason', 'group', 'kind', 'mode', 'level', 'tag'];

    /** Name words of date fields. */
    const DATE_WORDS = ['date', 'dt', 'tickler', 'deadline', 'received', 'opened', 'closed'];

    private $cfg;

    /**
     * @param array $config overrides for config('charts.dashboard')
     */
    public function __construct(array $config = [])
    {
        $this->cfg = array_merge([
            'sample_rows'      => 100000,   // rows looked at to judge how many distinct values a column has
            'top_values'       => 15,       // values listed per breakdown
            'max_ids'          => 8,
            'max_distribution' => 10,
            'max_code'         => 10,
            'max_other'        => 6,
            'max_trends'       => 4,
            'max_sampled'      => 60,       // columns whose distinct values are counted
            'max_distinct_other' => 200,    // "other" columns must have at most this many distinct values
            'max_distinct_named' => 5000,   // city, zip, ... may have many more
        ], $config);
    }

    public function config($key)
    {
        return $this->cfg[$key];
    }

    // ------------------------------------------------------------------
    // Column classification
    // ------------------------------------------------------------------

    private static function words($name)
    {
        return array_values(array_filter(explode('_', strtolower($name)), 'strlen'));
    }

    public static function hasWord($name, array $list)
    {
        return count(array_intersect(self::words($name), $list)) > 0;
    }

    public static function isDistributionName($name)
    {
        return self::hasWord($name, self::DISTRIBUTION_WORDS);
    }

    public static function isCodeName($name)
    {
        return self::hasWord($name, self::CODE_WORDS);
    }

    public static function isDateName($name)
    {
        return self::hasWord($name, self::DATE_WORDS);
    }

    private static function isShortText($type)
    {
        if (preg_match('/^(varchar|char)\((\d+)\)/i', $type, $m)) {
            return (int) $m[2] <= 150;
        }
        return (bool) preg_match('/^enum\(/i', $type);
    }

    private static function isWholeNumber($type)
    {
        return (bool) preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint)/i', $type);
    }

    /**
     * What to look at in a table.
     *
     * @param array $usable ChartQueryBuilder::usableColumns(): name => ['type', 'numeric', 'id']
     * @return array ['ids' => [...], 'dates' => [...], 'categorical' => [...]] (column names)
     */
    public function candidates(array $usable)
    {
        $ids = [];
        $dates = [];
        $categorical = [];
        foreach ($usable as $name => $info) {
            $type = $info['type'];
            if (!empty($info['id'])) {
                $ids[] = $name;
                continue;
            }
            if (self::isDateName($name) && (self::isWholeNumber($type) || self::isShortText($type))) {
                $dates[] = $name;
                continue;
            }
            $named = self::isDistributionName($name) || self::isCodeName($name);
            if (self::isShortText($type) || (self::isWholeNumber($type) && ($named || preg_match('/^(tinyint|smallint)/i', $type)))) {
                $categorical[] = $name;
            }
        }

        // columns named for who/where first, then types and codes, then the rest; capped
        usort($categorical, function ($a, $b) {
            return $this->rank($a) <=> $this->rank($b);
        });
        return [
            'ids'         => array_slice($ids, 0, $this->cfg['max_ids']),
            'dates'       => array_slice($dates, 0, $this->cfg['max_trends'] * 2),
            'categorical' => array_slice($categorical, 0, $this->cfg['max_sampled']),
        ];
    }

    private function rank($name)
    {
        return self::isDistributionName($name) ? 0 : (self::isCodeName($name) ? 1 : 2);
    }

    // ------------------------------------------------------------------
    // The plan
    // ------------------------------------------------------------------

    /**
     * Turn the statistics into what to run.
     *
     * @param array $candidates     from candidates()
     * @param array $distinct       column => distinct values among the sampled rows
     * @param int   $sampled        how many rows were sampled
     * @param array $dateRanges     column => [min, max] as YYYYMMDD ints, or null when it holds no valid dates
     * @return array ['ids' => [col...], 'breakdowns' => [['column', 'group']...], 'trends' => [['column', 'bucket']...]]
     */
    public function plan(array $candidates, array $distinct, $sampled, array $dateRanges)
    {
        $groups = ['distribution' => [], 'code' => [], 'other' => []];
        foreach ($candidates['categorical'] as $name) {
            if (!isset($distinct[$name])) {
                continue;
            }
            $d = (int) $distinct[$name];
            $named = self::isDistributionName($name) || self::isCodeName($name);
            if ($d < 2) {
                continue;     // one value: nothing to show
            }
            $tooMany = $named ? $d > $this->cfg['max_distinct_named'] : $d > $this->cfg['max_distinct_other'];
            // a column where nearly every row differs is an identifier or free text, not a category
            $mostlyUnique = $sampled >= 40 && $d > 0.5 * $sampled;
            if ($tooMany || ($mostlyUnique && !self::isDistributionName($name))) {
                continue;
            }
            $group = self::isDistributionName($name) ? 'distribution' : (self::isCodeName($name) ? 'code' : 'other');
            $groups[$group][] = $name;
        }

        $breakdowns = [];
        foreach (['distribution' => 'max_distribution', 'code' => 'max_code', 'other' => 'max_other'] as $group => $cap) {
            foreach (array_slice($groups[$group], 0, $this->cfg[$cap]) as $name) {
                $breakdowns[] = ['column' => $name, 'group' => $group];
            }
        }

        $trends = [];
        foreach ($candidates['dates'] as $name) {
            if (count($trends) >= $this->cfg['max_trends']) {
                break;
            }
            if (empty($dateRanges[$name])) {
                continue;
            }
            $trends[] = ['column' => $name, 'bucket' => self::bucketFor($dateRanges[$name][0], $dateRanges[$name][1])];
        }

        return ['ids' => $candidates['ids'], 'breakdowns' => $breakdowns, 'trends' => $trends];
    }

    /** Years when the dates span three or more years, otherwise months. */
    public static function bucketFor($min, $max)
    {
        return ((int) floor($max / 10000) - (int) floor($min / 10000)) >= 3 ? 'year' : 'month';
    }

    public function breakdownSpec($column)
    {
        return ['chart' => 'hbar', 'x' => ['column' => $column, 'bucket' => 'none'],
                'measure' => ['fn' => 'count'], 'limit' => $this->cfg['top_values']];
    }

    public function trendSpec($column, $bucket)
    {
        return ['chart' => 'line', 'x' => ['column' => $column, 'bucket' => $bucket], 'measure' => ['fn' => 'count']];
    }

    public static function label($column)
    {
        return preg_replace('/\bId\b/', 'ID', ChartQueryBuilder::pretty($column));
    }

    // ------------------------------------------------------------------
    // Summing up
    // ------------------------------------------------------------------

    /** Is a snapshot still the table's current data? Compares the table's updated_at at the time of counting. */
    public static function isFresh($snapshotTableUpdatedAt, $tableUpdatedAt)
    {
        if ($snapshotTableUpdatedAt === null || $tableUpdatedAt === null) {
            return false;
        }
        return substr((string) $snapshotTableUpdatedAt, 0, 19) === substr((string) $tableUpdatedAt, 0, 19);
    }

    /**
     * The headline numbers.
     *
     * @param array $items one per table the user may see:
     *        ['table_id', 'name', 'collection_id', 'collection', 'rows' (int|null), 'ids' => [['column', 'count']...]]
     * @return array tables, with_counts, collections, records, people, people_from, by_collection
     */
    public function overview(array $items)
    {
        $records = 0;
        $withCounts = 0;
        $people = null;
        $peopleFrom = null;
        $byCollection = [];
        foreach ($items as $it) {
            $cid = $it['collection_id'];
            if (!isset($byCollection[$cid])) {
                $byCollection[$cid] = ['id' => $cid, 'name' => $it['collection'], 'tables' => 0, 'records' => 0];
            }
            $byCollection[$cid]['tables']++;
            if ($it['rows'] !== null) {
                $withCounts++;
                $records += (int) $it['rows'];
                $byCollection[$cid]['records'] += (int) $it['rows'];
            }
            foreach (isset($it['ids']) ? $it['ids'] : [] as $id) {
                if (preg_match('/^(constituent|person)_id$/i', $id['column']) && ($people === null || $id['count'] > $people)) {
                    $people = (int) $id['count'];
                    $peopleFrom = $it['name'];
                }
            }
        }
        usort($byCollection, function ($a, $b) {
            return $b['records'] <=> $a['records'] ?: strcmp($a['name'], $b['name']);
        });
        return [
            'tables'        => count($items),
            'with_counts'   => $withCounts,
            'collections'   => count($byCollection),
            'records'       => $records,
            'people'        => $people,
            'people_from'   => $peopleFrom,
            'by_collection' => array_values($byCollection),
        ];
    }

    /** The light part of a snapshot kept beside the full payload, so the table list loads without decoding it. */
    public static function summaryOf(array $payload)
    {
        return [
            'rows'       => $payload['rows'],
            'ids'        => $payload['ids'],
            'breakdowns' => count($payload['breakdowns']),
            'trends'     => count($payload['trends']),
            'partial'    => !empty($payload['partial']),
        ];
    }
}
