<?php

namespace App\Jobs;

/**
 * Job behind the Import Large Dataset command
 * Command Execution Example: php artisan import:large-dataset name_of_file.txt name_of_collection
 *
 * The file is a mixed, delimited (default "|") export whose first field is the
 * record type (1A, 2A, ...). The import runs in two passes over the file:
 *
 *   1. scan: for every record type, find its layout (field names from the
 *      recordtypes table) and the type and size of every column, with the same
 *      rules as the tab-delimited import (see App\Helpers\LargeImportScan);
 *   2. load: create one table per record type, with one typed column per field,
 *      register it in the collection, and send the rows in chunks to
 *      ProcessChunkedFile jobs that insert them in batches.
 *
 * Typed columns (rather than one text column per line) are what lets the chart
 * builder, searches and exports work on the data. When the rows are in, each
 * table gets ID indexes and its ready-made chart results are queued.
 *
 * @author Logan Witzenburg (witzenbl@uni.edu)
*/

// Dependancies for this command 
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use App\Helpers\CSVHelper;
use App\Helpers\CustomStringHelper;
use App\Helpers\LargeImportHelper;
use App\Helpers\LargeImportScan;
use App\Helpers\TableHelper;
use App\Helpers\TableIndexHelper;
use App\Models\CMSRecords;
use App\Models\Collection;
use App\Models\Table;
use Symfony\Component\Process\Process;

class ImportFileInChunks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    // SETUP CODE
    // Preventing timeouts during batching
    public int $timeout = 0; 
    
    protected string $filename;
    protected string $tableName;
    protected int $collectionId;
    protected string $delimiter;
    protected int $chunkSize;
    protected bool $replace;

    /** rows held in memory across all record types before everything is sent */
    const MAX_BUFFERED = 100000;

    public function __construct(string $filename, string $tableName, int $collectionId = 0, string $delimiter = '|', int $chunkSize = 10000, bool $replace = false)
    {
        $this->filename = $filename;
        $this->tableName = $tableName;
        $this->collectionId = $collectionId;
        $this->delimiter = $delimiter;
        $this->chunkSize = max(100, $chunkSize);
        $this->replace = $replace;
    }

    public function handle(): void
    {
        try {
            // This checks to see if the file you are tyring to import is in the flatfiles directory or not.
            // In order for the command to work, it has to be in the flatfiles folder. 
            $filePath = storage_path("app/flatfiles/" . $this->filename);

            if (!file_exists($filePath)) {
                throw new \Exception("File not found at path: {$filePath}");
            }

            $collection = Collection::find($this->collectionId);
            if (!$collection) {
                throw new \Exception("Collection {$this->collectionId} was not found.");
            }

            $csv = new CSVHelper;
            $format = [new CustomStringHelper, 'formatFieldName'];

            // -------------------------------------------------------------------------------------------
            // STEP 1: scan the file: layout and column types for every record type
            $scan = new LargeImportScan($csv);
            $ignored = 0;
            $this->eachRecord($filePath, function ($recordType, $tokens) use ($scan) {
                $scan->add($recordType, $tokens);
            }, $ignored);

            if (count($scan->recordTypes()) === 0) {
                throw new \Exception("No records were found in {$this->filename}. Is the delimiter \"{$this->delimiter}\" right?");
            }
            Log::info("ImportFileInChunks {$this->filename}: scanned " . count($scan->recordTypes()) . " record types; ignored {$ignored} lines that are not records.");

            // -------------------------------------------------------------------------------------------
            // STEP 2: create a typed table for every record type
            $plans = [];
            foreach ($scan->recordTypes() as $recordType) {
                $plan = $scan->resolve($recordType, function ($type, $count) use ($collection) {
                    return $this->findLayout($type, $count, $collection);
                });
                $table = LargeImportHelper::tableName($this->tableName, $recordType);

                if (Schema::hasTable($table)) {
                    if (!$this->replace) {
                        Log::warning("ImportFileInChunks: table {$table} already exists, record type {$recordType} was not imported. Use --replace to drop and recreate it.");
                        continue;
                    }
                    Schema::drop($table);
                    Table::where('tblNme', $table)->delete();
                }

                $columns = LargeImportHelper::columnNames($plan['header'], $format);
                $types = $csv->fieldTypesFromCheck($plan['check']);
                (new TableHelper)->createTable($filePath, $this->filename, $table, $columns, $types, $collection->id);

                $plan['table'] = $table;
                $plan['columns'] = $columns;
                $plans[$recordType] = $plan;
                Log::info("ImportFileInChunks: created {$table} for record type {$recordType} ({$plan['rows']} rows, {$plan['count']} columns"
                    . ($plan['known'] ? '' : ', layout unknown: generated field names')
                    . ($plan['extended'] ? ", {$plan['extended']} trailing fields beyond the layout named Field#" : '')
                    . ($plan['padded'] ? ", {$plan['padded']} short rows padded" : '')
                    . ($plan['folded'] ? ", {$plan['folded']} long rows folded" : '') . ').');
            }

            // -------------------------------------------------------------------------------------------
            // STEP 3: stream the file again and send the rows in chunks
            $buffers = [];
            $buffered = 0;
            $now = date('Y-m-d H:i:s');
            $flush = function ($recordType) use (&$buffers, &$buffered, &$plans) {
                if (!empty($buffers[$recordType])) {
                    ProcessChunkedFile::dispatch($buffers[$recordType], $plans[$recordType]['table']);
                    $buffered -= count($buffers[$recordType]);
                    $buffers[$recordType] = [];
                }
            };
            $this->eachRecord($filePath, function ($recordType, $tokens) use (&$buffers, &$buffered, &$plans, $flush, $now) {
                if (!isset($plans[$recordType])) {
                    return;
                }
                $plan = $plans[$recordType];
                $values = LargeImportHelper::normalize($tokens, $plan['count'], $this->delimiter);

                $row = array_combine($plan['columns'], $values);
                $row['srchindex'] = LargeImportHelper::searchIndex($plan['header'], $values, $plan['known']);
                $row['created_at'] = $now;
                $row['updated_at'] = $now;

                $buffers[$recordType][] = $row;
                $buffered++;
                if (count($buffers[$recordType]) >= $this->chunkSize) {
                    $flush($recordType);
                }
                if ($buffered >= self::MAX_BUFFERED) {
                    foreach (array_keys($buffers) as $type) {
                        $flush($type);
                    }
                    gc_collect_cycles();
                }
            }, $ignored);
            foreach (array_keys($buffers) as $recordType) {
                $flush($recordType);
            }
            unset($buffers);

            // -------------------------------------------------------------------------------------------
            // STEP 4: spawn 8 background worker processes to drain the queue, and wait for them
            $workerProcesses = [];
            for ($i = 0; $i < 8; $i++) {
                // This for loop starts the artisan workers like you would to process other import jobs.
                $process = new Process([PHP_BINARY, base_path('artisan'), 'queue:work', '--stop-when-empty', '--tries=3']);
                $process->start();
                $workerProcesses[] = $process;
            }
            while (count($workerProcesses) > 0) {
                foreach ($workerProcesses as $key => $process) {
                    if (!$process->isRunning()) {
                        unset($workerProcesses[$key]);
                    }
                }
                usleep(250000);
            }

            // -------------------------------------------------------------------------------------------
            // STEP 5: when the last inserts are done, index the ID columns and queue the chart results
            $this->waitForInserts(array_column($plans, 'table'));
            foreach ($plans as $plan) {
                $this->finishTable($plan['table']);
            }
            Log::info("ImportFileInChunks {$this->filename}: finished, " . count($plans) . ' tables.');

        } catch (\Throwable $e) {
            Log::error("ImportFileInChunks Error on {$this->tableName}: " . $e->getMessage(), [
                "trace" => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Call $each($recordType, $tokens) for every record of the file.
     *
     * @param int $ignored counts the lines that were not records (blank, separators)
     */
    private function eachRecord(string $filePath, callable $each, int &$ignored): void
    {
        $ignored = 0;
        $handle = fopen($filePath, 'r');
        while (($line = fgets($handle)) !== false) {
            $tokens = LargeImportHelper::tokens($line, $this->delimiter);
            if ($tokens === null) {
                $ignored++;
                continue;
            }
            $each(strtoupper($tokens[0]), $tokens);
        }
        fclose($handle);
    }

    /**
     * Field names for a record type: the recordtypes layout with at least as
     * many fields as the rows usually have (the smallest such layout; the
     * collection's own CMS id first), or null when the type is not known.
     */
    private function findLayout(string $recordType, int $count, Collection $collection): ?array
    {
        if (!CMSRecords::isCMSRecord($recordType)) {
            return null;
        }
        $found = CMSRecords::findClosestCMSHeader($recordType, $count);
        if (count($found) === 0) {
            // every layout is shorter than the rows: take the largest (the scan adds
            // generated names for the extra trailing fields)
            $found = CMSRecords::getCMSHeader($recordType);
            if (count($found) === 0) {
                return null;
            }
            return unserialize($found[0]->fieldNames);
        }
        foreach ($found as $layout) {
            if ($collection->cmsId !== null && (string) $layout->cmsId === (string) $collection->cmsId) {
                return unserialize($layout->fieldNames);
            }
        }
        return unserialize($found[0]->fieldNames);
    }

    /**
     * Wait (up to two hours) until no ProcessChunkedFile job for these tables is
     * left in the queue: other workers may still be running the last ones.
     */
    private function waitForInserts(array $tables): void
    {
        $deadline = time() + 7200;
        while (time() < $deadline) {
            $pending = false;
            foreach ($tables as $table) {
                if (DB::table('jobs')->where('payload', 'like', '%ProcessChunkedFile%')->where('payload', 'like', '%' . $table . '%')->exists()) {
                    $pending = true;
                    break;
                }
            }
            if (!$pending) {
                return;
            }
            sleep(5);
        }
        Log::warning('ImportFileInChunks: gave up waiting for insert jobs to finish; indexing anyway.');
    }

    /**
     * Same follow-up as FileImport: ID indexes (for charts that combine
     * tables), a new updated_at, and the stored chart results.
     */
    private function finishTable(string $table): void
    {
        try {
            if (config('charts.index_on_import')) {
                (new TableIndexHelper)->addMissing($table);
            }
            $row = Table::where('tblNme', $table)->first();
            if ($row) {
                $row->touch();
                if (config('charts.summary_on_import')) {
                    RefreshChartSummaries::dispatch($table)->onQueue('low')->delay(now()->addMinutes(2));
                }
            }
        } catch (\Throwable $e) {
            Log::warning("ImportFileInChunks: follow-up for {$table} failed: " . $e->getMessage());
        }
    }
}
// ----------------------------------------------------------------
// END
// ----------------------------------------------------------------
