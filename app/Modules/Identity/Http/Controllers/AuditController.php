<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Http\Requests\IndexRequest;
use Illuminate\Http\JsonResponse;

class AuditController extends Controller
{
    public function index(IndexRequest $request): JsonResponse
    {
        $actor = $request->user('api');
        $query = AuditLog::withoutGlobalScopes()->where('tenant_id', $actor->tenant_id)
            ->with('actor:id,public_id')->latest('recorded_at');
        if ($request->filled('category')) {
            $query->where('category', $request->validated('category'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->validated('branch_id'));
        }
        if (! $actor->hasPermissionTo('identity.audit.view')) {
            $branchId = $request->attributes->get('auth_session')?->branch_id;
            if ($branchId === null || ($request->filled('branch_id') && $request->validated('branch_id') !== $branchId)) {
                throw new IdentityException('BRANCH_SCOPE_VIOLATION', 403, 'The audit query is outside the actor branch scope.');
            }
            $query->where('branch_id', $branchId);
        }
        $paginator = $query->paginate($request->perPage());
        $data = collect($paginator->items())->map(fn (AuditLog $log) => [
            'id' => $log->getKey(),
            'branch_id' => $log->branch_id,
            'category' => $log->category,
            'action' => $log->action,
            'source' => $log->source,
            'result' => $log->result,
            'reason' => $log->reason,
            'actor_id' => $log->actor?->public_id,
            'occurred_at' => $log->occurred_at->toIso8601String(),
            'recorded_at' => $log->recorded_at->toIso8601String(),
        ]);

        return response()->json(['data' => $data,
            'meta' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
                'total' => $paginator->total(), 'last_page' => $paginator->lastPage()]]);
    }
}
