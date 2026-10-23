<?php

namespace App\Jobs;
/**
 * Helper PHP File for ImportFileInChunks.php
 * This File is part of the import:large-dataset Command that I have implemented and created.
 * Command execution example: php artisan import:large-dataset name_of_file.txt name_of_collection
 *
 * @author Logan Witzenburg (witzenbl@uni.edu)
 *
 */

//Dependancies needed in this file
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
// ------------------------------------------------------

class ProcessChunkedFile implements ShouldQueue
{
use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

// Setting the timeout limits and how many max tries each batch job will have as well as other setup things.
public int $timeout = 300;
public int $tries = 3;

protected array $chunk;
protected string $tableName;
// ----------------------------------------------------------------------------------------------------------

public function __construct(array $chunk, string $tableName)
{
    $this->chunk = $chunk;
    $this->tableName = $tableName;
}

public function handle(): void
{
    try {
        if (empty($this->chunk)) {
            return;
        }

        // STEP 1: Split each job into 1,000-row sub-chunks to keep MySQL packet sizes safe
        $subChunks = array_chunk($this->chunk, 1000);
        // --------------------------------------------------------------------------------
        // STEP 2: Insert processed record sub-chunks into the MySQL database
        foreach ($subChunks as $batch) {
            DB::table($this->tableName)->insert($batch);
        }
        // --------------------------------------------------------------------------------
    } catch (\Throwable $e) {
        Log::error("ProcessChunkedFile failed on {$this->tableName}: " . $e->getMessage());
        throw $e;
    }
}
}
// --------------------------------------------------------
// END
// --------------------------------------------------------
