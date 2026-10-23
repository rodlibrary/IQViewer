<?php

namespace App\Console\Commands;

use App\Libraries\Charts\ChartService;
use App\Models\Table;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Artisan Command that (re)builds the stored chart results of imported tables.
 *
 * Imports queue this automatically; use it for tables imported earlier, or to
 * rebuild after changing the chart settings.
 *
 *   php artisan table:create:summary 2A1790000000    one table
 *   php artisan table:create:summary --all           every table
 *
 * Combined-table charts need the ID indexes first (table:create:index --all).
 * It can run for several minutes per table on millions of rows.
 */
class summaryTable extends Command
{
    protected $signature = 'table:create:summary {tablename?} {--all}';

    protected $description = 'Build the stored results of the ready-made charts for imported tables';

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

        $service = new ChartService;
        foreach ($names as $name) {
            $table = Table::where('tblNme', $name)->first();
            if (!$table || !Schema::hasTable($name)) {
                $this->error("{$name}: table doesn't exist.");
                continue;
            }
            $r = $service->refreshSummaries($table);
            $this->info("{$name}: built {$r['built']}, skipped {$r['skipped']}");
        }
        return 0;
    }
}
