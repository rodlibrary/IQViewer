<?php

namespace App\Console\Commands;

use App\Helpers\TableIndexHelper;
use App\Models\Table;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Artisan Command that adds ID indexes to imported tables.
 *
 * New imports are indexed automatically by the import job; use this for
 * tables that were imported before that existed.
 *
 * Command execution examples:
 *   php artisan table:create:index 2A1790000000          index one table
 *   php artisan table:create:index --all                 index every table
 *   php artisan table:create:index --all --dry-run       only show what would be indexed
 *
 * Building an index on a table with millions of rows takes a while and the
 * table is slow to write to meanwhile; run it when no import is in progress.
 */
class indexTable extends Command
{
    protected $signature = 'table:create:index {tablename?} {--all} {--dry-run}';

    protected $description = 'Add indexes to the ID columns of imported tables (needed for charts that combine tables)';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        if ($this->option('all')) {
            $names = Table::orderBy('tblNme')->pluck('tblNme')->all();
        } elseif ($this->argument('tablename')) {
            $names = [$this->argument('tablename')];
        } else {
            $this->error('Give a table name or use --all.');
            return 1;
        }

        $helper = new TableIndexHelper;
        foreach ($names as $name) {
            if (!Schema::hasTable($name)) {
                $this->error("{$name}: table doesn't exist.");
                continue;
            }
            $started = microtime(true);
            $columns = $helper->addMissing($name, (bool) $this->option('dry-run'));
            if (count($columns) === 0) {
                $this->line("{$name}: nothing to index");
            } else {
                $verb = $this->option('dry-run') ? 'would index' : 'indexed';
                $this->info("{$name}: {$verb} " . implode(', ', $columns) . ($this->option('dry-run') ? '' : sprintf(' (%.1fs)', microtime(true) - $started)));
            }
        }
        return 0;
    }
}
