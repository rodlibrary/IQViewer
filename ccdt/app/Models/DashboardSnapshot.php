<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The stored counts of one table for the data dashboard (see App\Libraries\Dashboard\DashboardService).
 */
class DashboardSnapshot extends Model
{
    protected $table = 'dashboard_snapshots';

    protected $guarded = [];
}
