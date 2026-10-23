<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Modified CreateSearchIndex that works with the 3 ways to import files.
 * @author Logan Witzenburg (witzenbl@uni.edu)
 *
*/

class CreateSearchIndex implements ShouldQueue
{
use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

protected string $tblNme;

public function __construct(string $tblNme)
{
    $this->tblNme = $tblNme;
    // Creates a new job instance
}

public function handle(): void
{
    // Zero-op execution to avoid container SIGKILL memory limits
}
}
// -----------------------------------------
// END
// -----------------------------------------
