<?php

namespace App\Adapters;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Models\Table;
use Log;

class SearchIndexAdapter {

    public function process($tblNme) {
        $cleanTable = trim($tblNme, "`");

        // 1. Ensure Table model metadata exists
        $table = Table::where("tblNme", $cleanTable)->first();
        if (!$table) {
            $colRecord = DB::table("collections")->where("clctnName", $cleanTable)->first();
            $colId = $colRecord ? $colRecord->id : 1;
            $id = DB::table("tables")->insertGetId([
                "tblNme" => $cleanTable,
                "collection_id" => $colId,
                "hasAccess" => 1,
                "created_at" => now(),
                "updated_at" => now(),
            ]);
            $table = Table::find($id);
        }

        // 2. Ensure physical table has srchindex column
        if (Schema::hasTable($cleanTable) && !Schema::hasColumn($cleanTable, "srchindex")) {
            Schema::table($cleanTable, function (Blueprint $table) {
                $table->longText("srchindex")->nullable();
            });
        }

        // 3. Inspect actual physical table columns
        $clmnLst = Schema::hasTable($cleanTable) 
            ? Schema::getColumnListing($cleanTable) 
            : (method_exists($table, "getColumnList") ? $table->getColumnList() : []);

        // 4. Filter system columns
        $excluded = ["id", "srchindex", "created_at", "updated_at", "deleted_at"];
        $searchableColumns = array_filter($clmnLst, function($col) use ($excluded) {
            return !in_array(strtolower($col), $excluded);
        });

        // 5. Execute index population in safe 50,000-row chunk batches
        if (!empty($searchableColumns)) {
            $concatSql = "LOWER(CONCAT_WS(\" \", `" . implode("` , `", $searchableColumns) . "`))";
            $maxId = DB::table($cleanTable)->max("id") ?? 0;
            $chunkSize = 50000;

            for ($start = 0; $start <= $maxId; $start += $chunkSize) {
                $end = $start + $chunkSize;
                DB::statement("UPDATE `" . $cleanTable . "` SET srchindex = {$concatSql} WHERE id > {$start} AND id <= {$end}");
            }
        }
    }
}
