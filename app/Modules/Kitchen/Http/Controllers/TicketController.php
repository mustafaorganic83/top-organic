<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Kitchen\Http\Requests\TicketActionRequest;
use App\Modules\Kitchen\Http\Resources\KitchenResource;
use App\Modules\Kitchen\Services\ChefAssignmentService;
use Illuminate\Http\JsonResponse;

/**
 * The kitchen board write side: chef assignment, priority flagging, and the
 * cooking → ready → served lifecycle transitions the line drives.
 */
class TicketController extends Controller
{
    public function assign(string $ticket, TicketActionRequest $request, ChefAssignmentService $service): JsonResponse
    {
        $chefId = $request->validated('chef_id');
        $model = $service->assign($request->kitchenContext(), $ticket,
            (int) $request->validated('expected_version'), $chefId === null ? null : (int) $chefId,
            (string) $request->validated('client_operation_id'));

        return response()->json(['data' => KitchenResource::ticket($model)]);
    }

    public function priority(string $ticket, TicketActionRequest $request, ChefAssignmentService $service): JsonResponse
    {
        $priority = $request->validated('priority');
        $model = $service->setPriority($request->kitchenContext(), $ticket,
            (int) $request->validated('expected_version'), (bool) $request->validated('is_priority'),
            $priority === null ? null : (int) $priority, (string) $request->validated('client_operation_id'));

        return response()->json(['data' => KitchenResource::ticket($model)]);
    }

    public function start(string $ticket, TicketActionRequest $request, ChefAssignmentService $service): JsonResponse
    {
        return $this->transition('start', $ticket, $request, $service);
    }

    public function ready(string $ticket, TicketActionRequest $request, ChefAssignmentService $service): JsonResponse
    {
        return $this->transition('ready', $ticket, $request, $service);
    }

    public function serve(string $ticket, TicketActionRequest $request, ChefAssignmentService $service): JsonResponse
    {
        return $this->transition('serve', $ticket, $request, $service);
    }

    private function transition(string $action, string $ticket, TicketActionRequest $request, ChefAssignmentService $service): JsonResponse
    {
        $model = $service->transition($request->kitchenContext(), $ticket,
            (int) $request->validated('expected_version'), $action,
            (string) $request->validated('client_operation_id'), $request->validated('reason'));

        return response()->json(['data' => KitchenResource::ticket($model)]);
    }
}
