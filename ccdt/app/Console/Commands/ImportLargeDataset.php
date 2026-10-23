<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Collection;
use App\Jobs\ImportFileInChunks;

class ImportLargeDataset extends Command
{
    protected $signature = "import:large-dataset {filename} {collection}";
    protected $description = "Dispatch flatfile import in chunked queue jobs using dynamic collection table lookup";

    public function handle()
    {
        $filename = $this->argument("filename");
        $collectionInput = $this->argument("collection");

        // Dynamically resolve collection by ID if numeric, otherwise by clctnName
        $collection = is_numeric($collectionInput)
            ? Collection::find($collectionInput)
            : Collection::where("clctnName", $collectionInput)->first();

        if (!$collection) {
            $this->error("Collection \"{$collectionInput}\" was not found in the database!");
            return 1;
        }

        // Get mapped table name from model (or default dynamically to collection name if not set)
        $tableName = $collection->table_name ?? $collection->clctnName;

        if (empty($tableName)) {
            $this->error("No table_name assigned to collection \"{$collectionInput}\".");
            return 1;
        }

        $this->info("Found Collection ID: {$collection->id} ({$collection->clctnName})");
        $this->info("Target Table: {$tableName}");
        $this->info("Dispatching chunked import job for file: {$filename}");

        ImportFileInChunks::dispatch($filename, $tableName);

        $this->info("Master chunking job successfully queued!");
        return 0;
    }
}
