<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The stored, aggregated result of one chart query (see App\Libraries\Charts\ChartService).
 */
class ChartSummary extends Model
{
    protected $table = 'chart_summaries';

    protected $guarded = [];
}
