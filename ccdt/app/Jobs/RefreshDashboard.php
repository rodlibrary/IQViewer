<?php

namespace App\Jobs;

use App\Libraries\Dashboard\DashboardService;
use App\Models\Table;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Log;

/**
 * Counts a table for the data dashboard (see DashboardService) after an import,
 * or when an administrator asks for fresh numbers.
 *
 * Like RefreshChartSummaries it waits until no import for the table is still
 * queued or running, and it never throws: a table that cannot be counted is
 * logged and left as it was.
 */
class RefreshDashboard implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $tblNme;

    // counting a very large table can take a while
    public $timeout = 3600;

    public function __construct($tblNme)
    {
        $this->tblNme = $tblNme;
    }

    /**
     * Keep waiting (see handle) for up to six hours instead of the worker's try count.
     */
    public function retryUntil()
    {
        return now()->addHours(6);
    }

    public function handle()
    {
        if ($this->importsPending()) {
            $this->release(120);
            return;
        }

        try {
            $table = Table::where('tblNme', $this->tblNme)->first();
            if (!$table) {
                return;
            }
            $snap = (new DashboardService)->refresh($table);
            Log::info("Dashboard counts for {$this->tblNme}: {$snap->row_count} rows in {$snap->seconds} s");
        } catch (\Throwable $e) {
            Log::warning("Dashboard counts for {$this->tblNme} failed: " . $e->getMessage());
        }
    }

    /**
     * Is a FileImport (or a chunk of a large-dataset import) for this table still waiting in, or being run from, the jobs table?
     */
    private function importsPending()
    {
        try {
            return DB::table('jobs')
                ->where(function ($q) {
                    $q->where('payload', 'like', '%FileImport%')->orWhere('payload', 'like', '%ProcessChunkedFile%');
                })
                ->where('payload', 'like', '%' . $this->tblNme . '%')
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
