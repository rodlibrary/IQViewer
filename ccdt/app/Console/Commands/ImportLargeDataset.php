<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Collection;
use App\Jobs\ImportFileInChunks;

class ImportLargeDataset extends Command
{
    /**
     * The file must be in storage/app/flatfiles. It is a mixed export whose first field is the
     * record type (1A, 2A, ...); one table per record type is created in the collection, named
     * <type>_<table name>, with one typed column per field so the data can be searched and charted.
     *
     *   --delimiter=X   field delimiter, default | ("tab" for a tab)
     *   --chunk=10000   rows per queue job
     *   --replace       drop and recreate tables that already exist (otherwise those record types are skipped)
     */
    protected $signature = "import:large-dataset {filename} {collection} {--delimiter= : Field delimiter, or tab (default |)} {--chunk=10000 : Rows per queue job} {--replace : Drop and recreate tables that already exist}";
    protected $description = "Dispatch a flatfile import in chunked queue jobs: one typed table per record type in the collection";

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
        // the pipe cannot be the default in the signature (a "|" there means an option shortcut)
        $given = $this->option("delimiter");
        $delimiter = ($given === null || $given === "") ? "|" : ($given === "tab" ? "\t" : (string) $given);
        if (strlen($delimiter) !== 1) {
            $this->error("The delimiter must be a single character (or \"tab\").");
            return 1;
        }

        $this->info("Table name base: {$tableName} (tables are named <record type>_{$tableName})");
        $this->info("Dispatching chunked import job for file: {$filename}");

        ImportFileInChunks::dispatch($filename, $tableName, (int) $collection->id, $delimiter, (int) $this->option("chunk"), (bool) $this->option("replace"));

        $this->info("Master chunking job successfully queued!");
        return 0;
    }
}
