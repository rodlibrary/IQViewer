<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Stored results of chart queries ("summary tables"). A chart that is slow to
 * compute (and every ready-made chart, after an import) keeps its aggregated
 * result here, so opening it later takes no query at all. Only aggregated
 * numbers are stored, never records.
 */
class CreateChartSummariesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::create('chart_summaries', function (Blueprint $table) {
            $table->increments('id');
            // md5 of the spec, the tables and their updated_at (see ChartService::cacheKey)
            $table->string('cache_key', 40)->unique();
            $table->integer('table_id')->unsigned();
            $table->integer('other_table_id')->unsigned()->nullable()->index();
            $table->text('spec');
            $table->longText('shaped');
            $table->integer('query_ms')->unsigned()->default(0);
            $table->timestamps();

            $table->index('table_id');
            $table->foreign('table_id')->references('id')->on('tables')->onDelete('cascade');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('chart_summaries');
    }
}
