<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessChunkedFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 3;

    protected array $chunk;
    protected string $tableName;

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

            // Split into 1,000-row sub-chunks to keep MySQL packet sizes safe
            $subChunks = array_chunk($this->chunk, 1000);

            foreach ($subChunks as $batch) {
                DB::table($this->tableName)->insert($batch);
            }
        } catch (\Throwable $e) {
            Log::error("ProcessChunkedFile failed on {$this->tableName}: " . $e->getMessage());
            throw $e;
        }
    }
}
