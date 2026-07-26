<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardService;
use Illuminate\Http\Request;

class ExecutiveDashboardController extends Controller
{
    public function __construct(private DashboardService $svc) {}

    public function summary(Request $r)
    {
        $filters = $this->filters($r);
        return response()->json($this->svc->summary($filters));
    }

    public function topIngredients(Request $r)
    {
        $filters = $this->filters($r);
        $limit = (int)$r->query('limit', 5);
        return response()->json($this->svc->topIngredients($filters, $limit));
    }

    public function topRecipes(Request $r)
    {
        $filters = $this->filters($r);
        $limit = (int)$r->query('limit', 5);
        return response()->json($this->svc->topRecipes($filters, $limit));
    }

    public function trend(Request $r)
    {
        $metric = $r->query('metric', 'cost');
        $interval = $r->query('interval', 'daily');
        $filters = $this->filters($r);
        return response()->json($this->svc->trend($metric, $interval, $filters));
    }

    private function filters(Request $r): array
    {
        return [
            'date_from' => $r->query('date_from'),
            'date_to' => $r->query('date_to'),
            'branch_id' => $r->query('branch_id'),
            'warehouse_id' => $r->query('warehouse_id'),
        ];
    }
}
