<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\RefreshDashboard;
use App\Libraries\Dashboard\DashboardPlanner;
use App\Models\DashboardSnapshot;
use App\Models\Table;

/**
 * Data dashboard: counts of what is in every imported table.
 *
 * Open to every signed-in user whose account has access (the same rule as the
 * chart builder, including CHARTS_ADMIN_ONLY). Users see only tables an
 * administrator has left enabled, in enabled collections. The numbers are read
 * from dashboard_snapshots, which DashboardService fills in the background
 * after each import; opening the page never queries the data tables. Groups
 * smaller than the minimum cell size were already hidden when they were
 * counted. Only administrators can ask for fresh counts.
 */
class DashboardController extends Controller
{
    private $planner;

    public function __construct(DashboardPlanner $planner = null) {
        $this->middleware('auth');

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

        $this->middleware('throttle:30,1')->only(['refresh']);
        $this->planner = $planner ?: new DashboardPlanner();
    }

    /**
     * Tables an administrator has not disabled, in enabled collections.
     */
    private function visibleTables() {
        return Table::where('hasAccess', true)->with('collection')->orderBy('tblNme')->get()
            ->filter(function ($t) {
                return $t->collection && $t->collection->isEnabled;
            })->values();
    }

    private function visibleTable($id) {
        $table = Table::findOrFail($id);
        if (!$table->hasAccess || !$table->collection || !$table->collection->isEnabled) {
            abort(403, 'Table is disabled');
        }
        return $table;
    }

    private function status($snapshot, $table) {
        if (!$snapshot) {
            return 'missing';
        }
        return DashboardPlanner::isFresh($snapshot->table_updated_at, $table->updated_at) ? 'ready' : 'stale';
    }

    /**
     * The dashboard page.
     */
    public function index() {
        return view('dashboard');
    }

    /**
     * The headline numbers and one light row per table (JSON).
     */
    public function data() {
        $tables = $this->visibleTables();
        $snaps = DashboardSnapshot::whereIn('table_id', $tables->pluck('id')->all())
            ->get(['table_id', 'table_updated_at', 'summary', 'computed_at', 'seconds'])->keyBy('table_id');

        $items = [];
        foreach ($tables as $t) {
            $snap = isset($snaps[$t->id]) ? $snaps[$t->id] : null;
            $sum = $snap ? json_decode($snap->summary, true) : null;
            $items[] = [
                'table_id'      => $t->id,
                'name'          => $t->tblNme,
                'collection_id' => $t->collection->id,
                'collection'    => $t->collection->clctnName,
                'status'        => $this->status($snap, $t),
                'rows'          => $sum ? $sum['rows'] : null,
                'ids'           => $sum ? $sum['ids'] : [],
                'breakdowns'    => $sum ? $sum['breakdowns'] : 0,
                'trends'        => $sum ? $sum['trends'] : 0,
                'partial'       => $sum ? $sum['partial'] : false,
                'computed_at'   => $snap && $snap->computed_at ? (string) $snap->computed_at : null,
            ];
        }

        return response()->json([
            'overview'      => $this->planner->overview($items),
            'tables'        => $items,
            'min_cell_size' => (int) config('charts.min_cell_size'),
            'can_refresh'   => (bool) auth()->user()->isAdmin,
        ]);
    }

    /**
     * Everything counted for one table (JSON).
     */
    public function show($id) {
        $table = $this->visibleTable($id);
        $snap = DashboardSnapshot::where('table_id', $table->id)->first();
        if (!$snap) {
            return response()->json(['error' => 'This table has not been counted yet.'], 404);
        }
        return response()->json([
            'table'       => ['id' => $table->id, 'name' => $table->tblNme, 'collection' => $table->collection->clctnName],
            'status'      => $this->status($snap, $table),
            'computed_at' => $snap->computed_at ? (string) $snap->computed_at : null,
            'payload'     => json_decode($snap->payload, true),
        ]);
    }

    /**
     * Administrators: count one table (table_id) or all visible tables (all=1)
     * again, in the background.
     */
    public function refresh(Request $request) {
        if (!$request->user()->isAdmin) {
            abort(403, 'Only administrators can refresh the dashboard.');
        }
        if ($request->input('all')) {
            $tables = $this->visibleTables();
        } else {
            $tables = collect([$this->visibleTable($request->input('table_id'))]);
        }
        foreach ($tables as $t) {
            RefreshDashboard::dispatch($t->tblNme)->onQueue('low');
        }
        return response()->json(['queued' => $tables->count()]);
    }
}
