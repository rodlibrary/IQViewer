<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A chart spec saved from the chart builder (see App\Libraries\Charts).
 */
class SavedChart extends Model
{
    protected $table = 'saved_charts';

    /**
     * The table this chart was built on.
     */
    public function sourceTable() {
        return $this->belongsTo('App\Models\Table', 'table_id');
    }

    /**
     * The decoded spec.
     *
     * @return array
     */
    public function specArray() {
        $spec = json_decode($this->spec, true);
        return is_array($spec) ? $spec : [];
    }
}
