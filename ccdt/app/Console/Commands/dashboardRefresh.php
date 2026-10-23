<?php

namespace App\Console\Commands;

use App\Jobs\RefreshDashboard;
use App\Libraries\Dashboard\DashboardService;
use App\Models\Table;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Artisan Command that counts imported tables for the data dashboard.
 *
 * Imports queue this automatically; use it for tables imported earlier or
 * after changing the dashboard settings.
 *
 *   php artisan dashboard:refresh 2A1790000000     one table, now
 *   php artisan dashboard:refresh --all            every table, now
 *   php artisan dashboard:refresh --all --queue    every table, in the background (queue "low")
 *
 * It can run for several minutes per table on millions of rows.
 */
class dashboardRefresh extends Command
{
    protected $signature = 'dashboard:refresh {tablename?} {--all} {--queue : Send the work to the queue instead of doing it now}';

    protected $description = 'Count imported tables for the data dashboard';

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

        $service = new DashboardService;
        foreach ($names as $name) {
            $table = Table::where('tblNme', $name)->first();
            if (!$table || !Schema::hasTable($name)) {
                $this->error("{$name}: table doesn't exist.");
                continue;
            }
            if ($this->option('queue')) {
                RefreshDashboard::dispatch($name)->onQueue('low');
                $this->info("{$name}: queued.");
                continue;
            }
            $snap = $service->refresh($table);
            $this->info("{$name}: {$snap->row_count} rows counted in {$snap->seconds} s");
        }
        return 0;
    }
}
