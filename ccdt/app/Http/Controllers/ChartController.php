<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Table;
use App\Models\SavedChart;
use App\Libraries\Charts\ChartService;
use App\Libraries\Charts\ChartSpecException;

/**
 * Chart builder.
 *
 * Open to every signed-in user whose account has access (set
 * CHARTS_ADMIN_ONLY=true to limit it to administrators). Users only see tables
 * an administrator has left enabled, in enabled collections, and only counts
 * and totals with small groups hidden; saved charts belong to the user who
 * saved them (administrators see all).
 *
 * The browser sends a JSON chart spec, never SQL. ChartService validates it
 * against the table's columns, runs one aggregate query on the read-only chart
 * connection and has the matplotlib service (charts-service/) draw it.
 */
class ChartController extends Controller
{
    private $charts;

    public function __construct(ChartService $charts = null) {
        $this->middleware('auth');

        // administrators only, when configured; and never a user whose access was withdrawn
        $this->middleware(function ($request, $next) {
            $user = $request->user();
            if (config('charts.admin_only') && !$user->isAdmin) {
                return redirect('home');
            }
            if (isset($user->hasAccess) && !$user->hasAccess && !$user->isAdmin) {
                abort(403, 'Your account does not have access.');
            }
            return $next($request);
        });

        $this->middleware('throttle:60,1')->only(['render', 'save']);
        $this->charts = $charts;
    }

    private function service() {
        if ($this->charts === null) {
            $this->charts = new ChartService();
        }
        return $this->charts;
    }

    /**
     * Only tables an administrator has not disabled, in enabled collections,
     * can be charted.
     */
    private function chartableTable($id) {
        $table = Table::findOrFail($id);
        if (!$table->hasAccess || ($table->collection && !$table->collection->isEnabled)) {
            abort(403, 'Table is disabled');
        }
        return $table;
    }

    /**
     * Saved charts the current user may see: their own, or all for admins.
     */
    private function visibleSaved() {
        $query = SavedChart::orderBy('name');
        if (!Auth::user()->isAdmin) {
            $query->where('user_id', Auth::id());
        }
        return $query;
    }

    /**
     * The chart builder page.
     */
    public function index(Request $request) {
        $tables = Table::where('hasAccess', true)->with('collection')->orderBy('tblNme')->get()
            ->filter(function ($t) {
                return $t->collection && $t->collection->isEnabled;
            })->values();
        $saved = $this->visibleSaved()->get();
        $preload = null;
        if ($request->query('saved')) {
            $preload = $this->visibleSaved()->findOrFail($request->query('saved'));
        }
        return view('charts')->with('tables', $tables)->with('saved', $saved)->with('preload', $preload);
    }

    /**
     * Columns, starter charts and combinable tables for one table (JSON).
     */
    public function describe($id) {
        return response()->json($this->service()->describe($this->chartableTable($id)));
    }

    /**
     * Columns, shared ID fields and starter charts for a table combined with
     * another table of the same collection (JSON).
     */
    public function describeJoin($id, $other) {
        $main = $this->chartableTable($id);
        try {
            $second = $this->service()->joinTable($main, $other);
            return response()->json($this->service()->describeJoin($main, $second));
        } catch (ChartSpecException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Run a spec and return the PNG (base64) plus the plotted numbers (JSON).
     */
    public function render(Request $request) {
        $table = $this->chartableTable($request->input('table_id'));
        $spec = $request->input('spec');
        if (!is_array($spec)) {
            return response()->json(['error' => 'Missing chart spec.'], 422);
        }

        try {
            $r = $this->service()->run($table, $spec);
        } catch (ChartSpecException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::warning('Chart query failed for table ' . $table->tblNme . ': ' . $e->getMessage());
            $timeout = stripos($e->getMessage(), 'execution time') !== false || stripos($e->getMessage(), 'interrupted') !== false;
            return response()->json(['error' => $timeout
                ? 'The query took too long. Add a filter (for example a date range) or choose a coarser grouping.'
                : 'The query could not be run.'], 422);
        } catch (\RuntimeException $e) {
            Log::error('Chart render failed: ' . $e->getMessage());
            return response()->json(['error' => 'The chart service is unavailable. ' . $e->getMessage()], 503);
        }

        return response()->json([
            'image'      => 'data:image/png;base64,' . $r['png'],
            'chart'      => $r['shaped']['chart'],
            'x_kind'     => $r['shaped']['x_kind'],
            'bucket'     => $r['shaped']['bucket'],
            'x'          => $r['shaped']['x'],
            'series'     => $r['shaped']['series'],
            'x_label'    => $r['shaped']['x_label'],
            'y_label'    => $r['shaped']['y_label'],
            'suppressed' => $r['shaped']['suppressed'],
            'title'      => $r['shaped']['title'],
            'query_ms'   => $r['query_ms'],
            'render_ms'  => $r['render_ms'],
            'cached'     => $r['cached'],
            'summary'    => !empty($r['summary']),
        ]);
    }

    /**
     * Save a chart spec so it can be reopened later.
     */
    public function save(Request $request) {
        $table = $this->chartableTable($request->input('table_id'));
        $name = trim((string) $request->input('name'));
        $spec = $request->input('spec');
        if ($name === '' || mb_strlen($name) > 120 || !is_array($spec)) {
            return response()->json(['error' => 'Give the chart a name (up to 120 characters).'], 422);
        }
        try {
            // refuse to store a spec that would not run
            $join = isset($spec['join']) && is_array($spec['join']) && !empty($spec['join']) ? $spec['join'] : null;
            $normalized = $this->service()->builder($table, $join)->normalize($spec);
            if ($join) {
                $normalized['join']['table_id'] = (int) $join['table_id'];
            }
        } catch (ChartSpecException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $chart = new SavedChart();
        $chart->name = $name;
        $chart->table_id = $table->id;
        $chart->user_id = Auth::id();
        $chart->spec = json_encode($normalized);
        $chart->save();

        return response()->json(['id' => $chart->id, 'name' => $chart->name, 'spec' => $normalized]);
    }

    /**
     * Delete a saved chart (the owner's, or any for an administrator).
     */
    public function delete(Request $request) {
        $this->visibleSaved()->findOrFail($request->input('id'))->delete();
        return response()->json(['ok' => true]);
    }
}
