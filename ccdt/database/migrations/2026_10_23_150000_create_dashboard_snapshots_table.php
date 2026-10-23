<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Stored counts for the data dashboard: one row per imported table. The page
 * only reads this table, so opening the dashboard runs no query on the data.
 * Only aggregated numbers are stored, never records.
 */
class CreateDashboardSnapshotsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::create('dashboard_snapshots', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('table_id')->unsigned()->unique();
            // the table's updated_at when it was counted: if the table has changed since, the counts are out of date
            $table->timestamp('table_updated_at')->nullable();
            $table->bigInteger('row_count')->unsigned()->default(0);
            $table->text('summary');          // rows, distinct IDs, how many breakdowns and trends
            $table->longText('payload');      // everything, for the table's detail view
            $table->integer('seconds')->unsigned()->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->foreign('table_id')->references('id')->on('tables')->onDelete('cascade');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('dashboard_snapshots');
    }
}
