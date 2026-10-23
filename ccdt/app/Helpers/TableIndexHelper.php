<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * Adds database indexes to the ID columns of an imported table.
 *
 * Imported tables only have a primary key (and the fulltext search index).
 * A chart that joins two large tables on an ID column (for example
 * communications to addresses on address_id) does not finish without an index
 * on that column. Building the indexes after the rows are loaded is much
 * cheaper than maintaining them during the import, so this runs once the
 * import job has finished (see App\Jobs\FileImport) and, for tables imported
 * earlier, from the table:create:index artisan command.
 *
 * Which columns count as IDs is configured in config/charts.php
 * (index_columns / index_exclude, matched against the column name). Tables
 * imported without a header have generic column names (Field0, Field1...) and
 * get no indexes.
 *
 * plan(), isIdColumn() and alterSql() are pure functions and can be tested
 * without a database.
 */
class TableIndexHelper
{
    /** Longest varchar column that is indexed whole. */
    const MAX_VARCHAR = 150;

    /**
     * Does this column name look like an ID column according to the config?
     *
     * @param string $name
     * @param array  $cfg  config('charts')
     * @return boolean
     */
    public static function isIdColumn($name, array $cfg)
    {
        $include = isset($cfg['index_columns']) ? $cfg['index_columns'] : [];
        $exclude = isset($cfg['index_exclude']) ? $cfg['index_exclude'] : [];
        foreach ($exclude as $pattern) {
            if (preg_match($pattern, $name)) {
                return false;
            }
        }
        foreach ($include as $pattern) {
            if (preg_match($pattern, $name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Can a column of this MySQL type be indexed whole and compared as an ID?
     */
    public static function isIndexableType($type)
    {
        if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint)/i', $type)) {
            return true;
        }
        if (preg_match('/^varchar\((\d+)\)/i', $type, $m)) {
            return (int) $m[1] <= self::MAX_VARCHAR;
        }
        return false;
    }

    /**
     * Columns that should get an index.
     *
     * @param array $columns column name => MySQL type (DESCRIBE)
     * @param array $indexed names of columns that already lead an index
     * @param array $cfg     config('charts')
     * @return string[]
     */
    public static function plan(array $columns, array $indexed, array $cfg)
    {
        $max = isset($cfg['index_max']) ? (int) $cfg['index_max'] : 10;
        $have = array_flip($indexed);
        $out = [];
        foreach ($columns as $name => $type) {
            if (strtolower($name) === 'id' || isset($have[$name])) {
                continue;
            }
            if (self::isIdColumn($name, $cfg) && self::isIndexableType($type)) {
                $out[] = $name;
                if (count($out) >= $max) {
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * One ALTER TABLE that adds every index (one table rebuild, not one per index).
     */
    public static function alterSql($table, array $columns)
    {
        $parts = [];
        foreach ($columns as $c) {
            $parts[] = 'ADD INDEX `' . substr('idx_' . $c, 0, 64) . '` (`' . str_replace('`', '``', $c) . '`)';
        }
        return 'ALTER TABLE `' . str_replace('`', '``', $table) . '` ' . implode(', ', $parts);
    }

    // ------------------------------------------------------------------
    // Database access
    // ------------------------------------------------------------------

    /**
     * Names of the columns that are the first column of some index.
     *
     * @param string      $table
     * @param string|null $connection null = default connection
     * @return string[]
     */
    public function indexedColumns($table, $connection = null)
    {
        $rows = DB::connection($connection)->select('SHOW INDEX FROM `' . str_replace('`', '``', $table) . '`');
        $out = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            if ((int) $r['Seq_in_index'] === 1) {
                $out[$r['Column_name']] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Column name => type for the table.
     */
    public function columnTypes($table, $connection = null)
    {
        $out = [];
        foreach (DB::connection($connection)->select('DESCRIBE `' . str_replace('`', '``', $table) . '`') as $c) {
            $c = (array) $c;
            $out[$c['Field']] = $c['Type'];
        }
        return $out;
    }

    /**
     * Add whatever ID indexes the table is missing. Safe to call again: it
     * does nothing when the indexes exist.
     *
     * @param string  $table
     * @param boolean $dryRun when true only report what would be added
     * @return string[] the columns that were (or would be) indexed
     */
    public function addMissing($table, $dryRun = false)
    {
        $cfg = config('charts');
        $todo = self::plan($this->columnTypes($table), $this->indexedColumns($table), $cfg);
        if ($todo && !$dryRun) {
            DB::statement(self::alterSql($table, $todo));
        }
        return $todo;
    }
}
