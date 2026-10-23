<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Updated OptimizeSearchIndex which dispatches OptimizeSearch jobs across a collection, and they show up in the low priority in the queues.
 * Works with all 3 ways to import.
 * @author Logan Witzenburg (witzenbl@uni.edu)
 */

class OptimizeSearchIndex implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $tblNme;
    // Creates new job instance

    public function __construct(string $tblNme)
    {
        $this->tblNme = $tblNme;
    }

    public function handle()
    {
        $cleanTable = trim($this->tblNme, "`");
        
        // Fast index lookup for max ID (instant execution on 15M rows)
        $maxIdRow = DB::selectOne("SELECT id FROM `{$cleanTable}` ORDER BY id DESC LIMIT 1");
        $maxId = $maxIdRow ? (int) $maxIdRow->id : 0;
        $chunkSize = 50000;
        // -------------------------------------
        for ($start = 0; $start <=$maxId; $start +=$chunkSize) {
            dispatch(new OptimizeSearch($cleanTable, $start,$chunkSize))->onQueue('default');
        }
    }
}
// -----------------------------------------
// END
// ----------------------------------------

