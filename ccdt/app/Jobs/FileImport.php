<?php

namespace App\Jobs;

use App\Adapters\ImportAdapter;
use App\Helpers\TableIndexHelper;
use App\Models\Table;
use Illuminate\Bus\Queueable;
use Illuminate\Http\Request;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Log;


/**
 * File Imort Job calls the Import adapter to 
 * import a file into a table.
 * 
 * @author Tracy A McCormick <tam0013@mail.wvu.edu>
 */
class FileImport implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    private $tblNme;
    private $fltFlePath;
    private $fltFle;

    /**
     * Create a new job instance.
     *
     * @param string $tblNme (name of the table)
     * @param string $fltFlePath (path to the file)
     * @param string $fltFle (name of the file)
     *  
     */
    public function __construct($tblNme, $fltFlePath, $fltFle)
    {
        $this->tblNme = $tblNme;
        $this->fltFlePath = $fltFlePath;
        $this->fltFle = $fltFle;
    }

    /**
     * The handle function contains code to be executed for 
     * the job. 
     * 
     * Handle calls the Import Adapter it reads the file and inserts
     * each record into the specified table.
     * 
     * @return void
     */
    public function handle()
    {
        $adapter = new ImportAdapter($this->tblNme, $this->fltFlePath, $this->fltFle);
        // import file into table
        $adapter->process();

        // Index the ID columns so charts can join this table to others. The
        // rows are already imported at this point, so a failure here must not
        // fail the job (a retry would import the file a second time).
        if (config('charts.index_on_import')) {
            try {
                $columns = (new TableIndexHelper)->addMissing($this->tblNme);
                if (count($columns) > 0) {
                    Log::info("Import for table {$this->tblNme}: indexed " . implode(', ', $columns));
                }
            } catch (\Throwable $e) {
                Log::warning("Import for table {$this->tblNme}: could not add ID indexes: " . $e->getMessage());
            }
        }

        // New rows make stored chart results out of date: move the table's
        // updated_at (part of every chart cache key), then rebuild the
        // ready-made charts in the background. Same rule: never fail the job.
        try {
            $table = Table::where('tblNme', $this->tblNme)->first();
            if ($table) {
                $table->touch();
                if (config('charts.summary_on_import')) {
                    RefreshChartSummaries::dispatch($this->tblNme)->onQueue('low')->delay(now()->addMinutes(2));
                }
                if (config('charts.dashboard_on_import')) {
                    RefreshDashboard::dispatch($this->tblNme)->onQueue('low')->delay(now()->addMinutes(2));
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Import for table {$this->tblNme}: could not queue chart summaries: " . $e->getMessage());
        }
    }
}
