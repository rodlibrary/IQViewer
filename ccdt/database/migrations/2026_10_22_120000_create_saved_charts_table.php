<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Chart specs saved from the chart builder. Only the spec (a small JSON
 * document) is stored; charts are drawn on demand from the current data.
 */
class CreateSavedChartsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::create('saved_charts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 120);
            $table->integer('table_id')->unsigned();
            $table->integer('user_id')->unsigned()->nullable();
            $table->text('spec');
            $table->timestamps();

            // Saved charts go away with their table
            $table->foreign('table_id')
                  ->references('id')
                  ->on('tables')
                  ->onDelete('cascade');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('saved_charts');
    }
}
