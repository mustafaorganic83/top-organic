<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\KdsTicket;
use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Http\Requests\IndexRequest;
use App\Modules\Sales\Http\Requests\KitchenRequest;
use App\Modules\Sales\Http\Resources\SalesResource;
use App\Modules\Sales\Services\KitchenService;
use Illuminate\Http\JsonResponse;

class KitchenController extends Controller
{
    public function queue(IndexRequest $r): JsonResponse
    {
        $c = $r->salesContext();
        $q = KdsTicket::withoutGlobalScopes()->where('tenant_id', $c->tenantId)->where('branch_id', $c->branchId)
            ->whereIn('state', ['queued', 'in_progress', 'ready'])->with(['station', 'items', 'events']);
        if ($r->filled('station_id')) {
            $q->where('kds_station_id', $r->validated('station_id'));
        }
        if ($r->filled('state')) {
            $q->where('state', $r->validated('state'));
        }

        return response()->json(SalesResource::paginated($q->orderBy('priority')->oldest()->paginate($r->perPage()), SalesResource::ticket(...)));
    }

    public function show(string $ticket, KitchenRequest $r): JsonResponse
    {
        $c = $r->salesContext();
        $m = KdsTicket::withoutGlobalScopes()->where('tenant_id', $c->tenantId)->where('branch_id', $c->branchId)->find($ticket)
            ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The kitchen ticket was not found.');

        return response()->json(['data' => SalesResource::ticket($m)]);
    }

    public function dispatch(KitchenRequest $r, KitchenService $s): JsonResponse
    {
        return response()->json(['data' => $s->dispatch($r->salesContext(), $r->validated('order_id'),
            $r->validated('client_operation_id'))->map(SalesResource::ticket(...))->values()], 201);
    }

    public function start(string $ticket, KitchenRequest $r, KitchenService $s): JsonResponse
    {
        return $this->transition($ticket, 'start', $r, $s);
    }

    public function ready(string $ticket, KitchenRequest $r, KitchenService $s): JsonResponse
    {
        return $this->transition($ticket, 'ready', $r, $s);
    }

    public function bump(string $ticket, KitchenRequest $r, KitchenService $s): JsonResponse
    {
        return $this->transition($ticket, 'bump', $r, $s);
    }

    public function recall(string $ticket, KitchenRequest $r, KitchenService $s): JsonResponse
    {
        return $this->transition($ticket, 'recall', $r, $s);
    }

    private function transition(string $id, string $action, KitchenRequest $r, KitchenService $s): JsonResponse
    {
        return response()->json(['data' => SalesResource::ticket($s->transition($r->salesContext(), $id,
            $r->integer('expected_version'), $action, $r->validated('client_operation_id'), $r->validated('reason')))]);
    }
}
