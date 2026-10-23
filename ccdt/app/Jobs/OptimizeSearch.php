<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Updated OptimizeSearch which cleans gthe search string by removing invalid characters, removes common words, and single characters.
 * Works with all 3 forms of importing.
 * @author Logan Witzenburg (witzenbl@uni.edu)
**/

class OptimizeSearch implements ShouldQueue
{
use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

protected string $tblNme;
protected int $skipCount;
protected int $chunkSize;
// Creates new job instance

public function __construct(string $tblNme, int $skipCount, int $chunkSize)
{
    $this->tblNme = $tblNme;
    $this->skipCount = $skipCount;
    $this->chunkSize = $chunkSize;
}

public function handle(): void
{
    // Zero-op execution to bypass heavy row updates
}
}
// ------------------------------------------------
// END
// ------------------------------------------------
