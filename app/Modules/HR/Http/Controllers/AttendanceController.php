<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\HrRequest;
use App\Modules\HR\Services\AttendanceService;
use Illuminate\Http\JsonResponse;

class AttendanceController extends Controller
{
    public function index(string $employee, HrRequest $request, AttendanceService $service): JsonResponse
    {
        $records = $service->list($request->hrContext(), $employee, $request->query('from'), $request->query('to'));

        return response()->json(['data' => $records->values()->all()]);
    }

    public function checkIn(HrRequest $request, AttendanceService $service): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|string',
            'work_date' => 'nullable|date',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'photo' => 'nullable|string|max:512',
        ]);

        return response()->json(['data' => $service->checkIn($request->hrContext(), $data)], 201);
    }

    public function checkOut(string $attendance, HrRequest $request, AttendanceService $service): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'photo' => 'nullable|string|max:512',
            'expected_version' => 'required|integer|min:0',
        ]);

        return response()->json(['data' => $service->checkOut($request->hrContext(), $attendance, $data)]);
    }
}
