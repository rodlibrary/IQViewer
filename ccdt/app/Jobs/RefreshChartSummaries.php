<?php

namespace App\Jobs;

use App\Libraries\Charts\ChartService;
use App\Models\Table;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Log;

/**
 * Rebuilds the stored results of the ready-made charts for a table (alone and
 * combined with the other tables of its collection) after an import, so those
 * charts open instantly.
 *
 * A collection is usually imported file by file, so the job waits until no
 * import for the table is still queued or running. It never throws: a chart
 * that cannot be built is skipped and logged.
 */
class RefreshChartSummaries implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $tblNme;

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
            $r = (new ChartService)->refreshSummaries($table);
            Log::info("Chart summaries for {$this->tblNme}: built {$r['built']}, skipped {$r['skipped']}");
        } catch (\Throwable $e) {
            Log::warning("Chart summaries for {$this->tblNme} failed: " . $e->getMessage());
        }
    }

    /**
     * Is a FileImport for this table still waiting in, or being run from, the jobs table?
     */
    private function importsPending()
    {
        try {
            return DB::table('jobs')
                ->where('payload', 'like', '%FileImport%')
                ->where('payload', 'like', '%' . $this->tblNme . '%')
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
