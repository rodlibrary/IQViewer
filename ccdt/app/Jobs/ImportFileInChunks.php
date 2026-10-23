<?php

namespace App\Jobs;

/**
 * Artisan Command for Importing Large Datasets
 * Command Execution Example: php artisan import:large-dataset name_of_file.txt name_of_collection
 * Command sends out jobs of 100,000 records that will be processed individually in batches, which saves a lot of time in the impot stage.
 * @author Logan Witzenburg (witzenbl@uni.edu)
*/

// Dependancies for this command 
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessChunkedFile;
use Symfony\Component\Process\Process;

class ImportFileInChunks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    // SETUP CODE
    // Preventing timeouts during batching
    public int $timeout = 0; 
    
    protected string $filename;
    protected string $tableName;

    public function __construct(string $filename, string $tableName)
    {
        $this->filename = $filename;
        $this->tableName = $tableName;
    }

    public function handle(): void
    {
        try {
            // STEP 0: Spawn 8 background worker processes directly inside PHP
            $workerProcesses = [];
            $numWorkers = 8;

            for ($i = 0; $i < $numWorkers; $i++) {
                // This for loop starts the artisan workers like you would to process other import jobs.
                $process = new Process([PHP_BINARY, base_path('artisan'), 'queue:work', '--stop-when-empty', '--tries=3']);
                $process->start(); 
                $workerProcesses[] = $process;
                // -------------------------------------------------------------------------------------
            }
            // This checks to see if the file you are tyring to import is in the flatfiles directory or not.
            // In order for the command to work, it has to be in the flatfiles folder. 
            $filePath = storage_path("app/flatfiles/" . $this->filename);

            if (!file_exists($filePath)) {
                throw new \Exception("File not found at path: {$filePath}");
            }

            $cleanTable = trim($this->tableName, "`");
            // -------------------------------------------------------------------------------------------
            // STEP 1: Ensure target schema exists
            if (!Schema::hasTable($cleanTable)) {
                Schema::create($cleanTable, function (Blueprint $table) {
                    $table->id();
                    $table->string("record_type")->nullable();
                    $table->string("constituent_id")->nullable();
                    $table->longText("data")->nullable();
                    $table->text("srchindex")->nullable();
                    $table->timestamps();
                });
            }
            // --------------------------------------------------------------------------------------------

            // STEP 2. Metadata definitions for dynamic search index building
            $excludeKeywords = [
                'RECORD TYPE', 'ID', 'FLAG', 'DATE', 'CMSID', 
                'SEQUENCE NUMBER', 'CREATED_AT', 'UPDATED_AT'
            ];
            // This code dynamically checks what record type the file contents are using RecordTypesSeeder.php.
            $schemaMap = [
                '1A' => ['Record Type', 'Constituent ID', 'Individual Type', 'Prefix', 'First Name', 'Middle Name', 'Last Name', 'Suffix', 'Appellation', 'Salutation', 'Date of Birth', 'No Mail Flag', 'Deceased Flag'],
                '1B' => ['Record Type', 'Constituent ID', 'Address ID', 'Address Type', 'Primary Flag', 'Default Address Flag', 'Title', 'Organization Name', 'Address line 1', 'Address line 2', 'Address line 3', 'Address line 4', 'City', 'State', 'Zip Code', 'Carrier Route', 'County', 'Country', 'District', 'Precinct', 'No Mail Flag', 'Agency Code'],
                '1C' => ['Record Type', 'Constituent ID', 'Constituent Code Type', 'Constituent Code'],
                '1D' => ['Record Type', 'Constituent ID', '1D Sequence Number', 'Constituent Text Type', 'Constituent Text'],
                '1E' => ['Record Type', 'Constituent ID', 'Constituent Phone or email Type', 'Phone Number Email or URL'],
                '2A' => ['Record Type', 'Constituent ID', 'Correspondence ID', 'Correspondence Type', 'Staff', 'Date In', 'Date Out', 'Tickler date', 'Update Date', 'Response Type', 'Address ID', 'Household Flag', 'Household ID'],
                '2B' => ['Record Type', 'Constituent ID', 'Correspondence ID', 'Correspondence Code', 'Position'],
                '2C' => ['Record Type', 'Constituent ID', 'Correspondence ID', '2C Sequence Number', 'Document Type', 'Correspondence Document Name', 'File Location'],
                '2D' => ['Record Type', 'Constituent ID', 'Correspondence ID', '2D Sequence Number', 'Text Type', 'Correspondence Text'],
                '2E' => ['Record Type', 'Constituent ID', 'Correspondence ID', 'Merge Field Name', 'Merge Data'],
                '3A' => ['Record Type', 'Constituent ID', 'Casework ID', 'Staff', 'Start Date', 'End Date', 'Tickler date', 'Update Date', 'Casework Description', 'Casework Status', 'Agency ID'],
                '3B' => ['Record Type', 'Constituent ID', 'Casework ID', 'Casework Code'],
                '3D' => ['Record Type', 'Constituent ID', 'Casework ID', '3D Sequence Number', 'Text Type', 'Casework Text'],
                '3E' => ['Record Type', 'Constituent ID', 'Casework ID', 'Party ID'],
                '4A' => ['Record Type', 'Constituent ID', 'Casework ID', 'Transaction Type', 'Staff', 'Start Date', 'End Date', 'Tickler date', 'Update Date', 'Response Type', 'Address ID', 'Household Flag', 'Household ID', 'Contacted Party ID'],
                '4B' => ['Record Type', 'Constituent ID', 'Casework ID', 'Transaction ID', 'Transaction Code', 'Position'],
                '4C' => ['Record Type', 'Constituent ID', 'Casework ID', 'Transaction ID', '4C Sequence Number', 'Document Type', 'Casework Transaction Document Name', 'File Location'],
                '4D' => ['Record Type', 'Constituent ID', 'Casework ID', 'Transaction ID', '4D Sequence Number', 'Text Type', 'Transaction Text'],
                '4E' => ['Record Type', 'Constituent ID', 'Casework ID', 'Transaction ID', 'Merge Field Name', 'Merge Data'],
                '4F' => ['Record Type', 'Constituent ID', 'Casework ID', 'Transaction ID', 'Party ID'],
                '5A' => ['Record Type', 'Household ID', 'Household Salutation', 'Constituent ID', 'Primary Contact Flag'],
                '7A' => ['Record Type', 'Event ID', 'Event Description', 'Contact', 'Country', 'Organization', 'Start Date', 'Start Time', 'End Date', 'End Time', 'Location', 'Status', 'Private Flag', 'Notes', 'Schedule Date', 'Scheduled by', 'Revision Date', 'Revised By'],
                '7B' => ['Record Type', 'Event ID', 'Event Code'],
                '7C' => ['Record Type', 'Event ID', 'User ID'],
                '8A' => ['Record Type', 'Code Type', 'Code', 'Code Description', 'Inactive Flag'],
                '8C' => ['Record Type', 'Letter Code', 'Letter Description', 'Document Name', 'Owner', 'Creation Date', 'Revision Date', 'Archive Flag'],
                '8D' => ['Record Type', 'Letter Code', 'Merge Field Name'],
                '8E' => ['Record Type', 'Letter Code', 'Correspondence or Transaction Code', 'Code Type'],
            ];
            // -----------------------------------------------------------------------------------------------------------------------
            // STEP 3. Stream file and dispatch chunks safely to ProcessChunkedFile.php, another newer file I wrote.
            $handle = fopen($filePath, "r");
            $chunk = [];
            $chunkSize = 10000;

            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if (empty($trimmed)) continue;

                $f = explode("|", $trimmed);
                $recType = strtoupper($f[0] ?? '');

                $searchTerms = [];

                if (isset($schemaMap[$recType])) {
                    foreach ($schemaMap[$recType] as $idx => $fieldName) {
                        $val = trim($f[$idx] ?? '');
                        if ($val === '') continue;

                        $upperField = strtoupper($fieldName);
                        $isExcluded = false;

                        foreach ($excludeKeywords as $kw) {
                            if (str_contains($upperField, $kw)) {
                                if ($upperField === 'CONSTITUENT ID') {
                                    break;
                                }
                                $isExcluded = true;
                                break;
                            }
                        }

                        if (!$isExcluded) {
                            $searchTerms[] = $val;
                        }
                    }
                } else {
                    $searchTerms = array_slice($f, 1);
                }

                $chunk[] = [
                    "record_type"    => $f[0] ?? null,
                    "constituent_id" => $f[1] ?? null,
                    "data"           => $trimmed,
                    "srchindex"      => implode(' ', array_filter($searchTerms)),
                    "created_at"     => now(),
                    "updated_at"     => now(),
                ];

                if (count($chunk) === $chunkSize) {
                    ProcessChunkedFile::dispatch($chunk, $cleanTable);
                    unset($chunk);
                    $chunk = [];
                    gc_collect_cycles();
                }
            }

            if (!empty($chunk)) {
                ProcessChunkedFile::dispatch($chunk, $cleanTable);
                unset($chunk);
            }

            fclose($handle);

            // -----------------------------------------------------------------------------------------------------------
            // STEP 4: Wait for the 8 background processes to finish processing
            while (count($workerProcesses) > 0) {
                foreach ($workerProcesses as $key => $process) {
                    if (!$process->isRunning()) {
                        unset($workerProcesses[$key]);
                    }
                }
                usleep(250000);
            }

        } catch (\Throwable $e) {
            Log::error("ImportFileInChunks Error on {$this->tableName}: " . $e->getMessage(), [
                "trace" => $e->getTraceAsString()
            ]);
            throw $e;
        }
        // ------------------------------------------------------------------------------------------
    }
}
// ----------------------------------------------------------------
// END
// ----------------------------------------------------------------
