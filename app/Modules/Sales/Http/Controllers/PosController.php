<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CashDrawerSession;
use App\Models\DiningTable;
use App\Models\Floor;
use App\Models\PosShift;
use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Http\Requests\PosRequest;
use App\Modules\Sales\Services\PosService;
use Illuminate\Http\JsonResponse;

class PosController extends Controller
{
    public function openShift(PosRequest $r, PosService $s): JsonResponse
    {
        return response()->json(['data' => $this->shift($s->openShift($r->salesContext()))], 201);
    }

    public function showShift(string $shift, PosRequest $r): JsonResponse
    {
        $c = $r->salesContext();
        $model = PosShift::withoutGlobalScopes()->where('tenant_id', $c->tenantId)->where('branch_id', $c->branchId)
            ->whereKey($shift)->first() ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The POS shift was not found.');

        return response()->json(['data' => $this->shift($model)]);
    }

    public function closeShift(string $shift, PosRequest $r, PosService $s): JsonResponse
    {
        return response()->json(['data' => $this->shift($s->closeShift($r->salesContext(), $shift, $r->integer('expected_version')))]);
    }

    public function openDrawer(PosRequest $r, PosService $s): JsonResponse
    {
        $m = $s->openDrawerSession($r->salesContext(), $r->validated('shift_id'), $r->validated('drawer_id'),
            $r->validated('currency'), $r->integer('opening_amount'));

        return response()->json(['data' => $this->drawer($m)], 201);
    }

    public function closeDrawer(string $session, PosRequest $r, PosService $s): JsonResponse
    {
        return response()->json(['data' => $this->drawer($s->closeDrawerSession($r->salesContext(), $session,
            $r->integer('counted_amount'), $r->integer('expected_version')))]);
    }

    public function movement(string $session, PosRequest $r, PosService $s): JsonResponse
    {
        $m = $s->recordCashMovement($r->salesContext(), $session, $r->validated('type'), $r->integer('amount'),
            $r->validated('currency'), $r->validated('client_operation_id'), $r->validated('reason'));

        return response()->json(['data' => ['id' => $m->id, 'drawer_session_id' => $m->cash_drawer_session_id,
            'type' => $m->type, 'amount' => $m->amount, 'currency' => $m->currency, 'reason' => $m->reason,
            'occurred_at' => $m->occurred_at?->toISOString()]], 201);
    }

    public function reverseMovement(string $movement, PosRequest $r, PosService $s): JsonResponse
    {
        $m = $s->reverseCashMovement($r->salesContext(), $movement, $r->validated('client_operation_id'), $r->validated('reason'));

        return response()->json(['data' => ['id' => $m->id, 'original_movement_id' => $m->original_movement_id,
            'type' => $m->type, 'amount' => $m->amount, 'currency' => $m->currency, 'reason' => $m->reason]], 201);
    }

    public function floors(PosRequest $r): JsonResponse
    {
        $c = $r->salesContext();
        $floors = Floor::withoutGlobalScopes()->where('tenant_id', $c->tenantId)->where('branch_id', $c->branchId)
            ->where('status', 'active')->with(['tables' => fn ($q) => $q->withoutGlobalScopes()->where('status', 'active')
            ->with(['sessions' => fn ($s) => $s->withoutGlobalScopes()->where('state', 'open')])])->get();

        return response()->json(['data' => $floors->map(fn ($floor) => ['id' => $floor->id, 'code' => $floor->code,
            'name' => $floor->name, 'layout_revision' => $floor->layout_revision, 'layout' => $floor->layout,
            'tables' => $floor->tables->map(fn ($table) => ['id' => $table->id, 'code' => $table->code,
                'name' => $table->name, 'capacity' => $table->capacity, 'status' => $table->status,
                'active_session_id' => $table->sessions->first()?->id])->values()])->values()]);
    }

    public function tables(PosRequest $r): JsonResponse
    {
        $c = $r->salesContext();
        $tables = DiningTable::withoutGlobalScopes()->where('tenant_id', $c->tenantId)
            ->where('branch_id', $c->branchId)->where('status', 'active')->with([
                'floor' => fn ($q) => $q->withoutGlobalScopes(),
                'sessions' => fn ($q) => $q->withoutGlobalScopes()->where('state', 'open'),
            ])->orderBy('sort_order')->get();

        return response()->json(['data' => $tables->map(fn ($table) => [
            'id' => $table->id, 'code' => $table->code, 'name' => $table->name,
            'capacity' => $table->capacity, 'floor' => ['id' => $table->floor->id, 'code' => $table->floor->code,
                'name' => $table->floor->name], 'active_session_id' => $table->sessions->first()?->id,
        ])->values()]);
    }

    public function openTable(PosRequest $r, PosService $s): JsonResponse
    {
        $m = $s->openTableSession($r->salesContext(), $r->validated('table_id'), $r->integer('guest_count'));

        return response()->json(['data' => $this->table($m)], 201);
    }

    public function closeTable(string $session, PosRequest $r, PosService $s): JsonResponse
    {
        return response()->json(['data' => $this->table($s->closeTableSession($r->salesContext(), $session, $r->integer('expected_version')))]);
    }

    private function shift($m): array
    {
        return ['id' => $m->id, 'business_date' => (string) $m->business_date, 'sequence' => $m->sequence,
            'state' => $m->state, 'opened_at' => $m->opened_at?->toISOString(), 'closed_at' => $m->closed_at?->toISOString(), 'lock_version' => $m->lock_version];
    }

    private function drawer(CashDrawerSession $m): array
    {
        return ['id' => $m->id, 'drawer_id' => $m->cash_drawer_id,
            'shift_id' => $m->pos_shift_id, 'currency' => $m->currency, 'opening_amount' => $m->opening_amount,
            'expected_amount' => $m->expected_amount, 'counted_amount' => $m->counted_amount, 'variance_amount' => $m->variance_amount,
            'state' => $m->state, 'lock_version' => $m->lock_version];
    }

    private function table($m): array
    {
        return ['id' => $m->id, 'table_id' => $m->dining_table_id,
            'guest_count' => $m->guest_count, 'state' => $m->state, 'opened_at' => $m->opened_at?->toISOString(),
            'closed_at' => $m->closed_at?->toISOString(), 'lock_version' => $m->lock_version];
    }
}
