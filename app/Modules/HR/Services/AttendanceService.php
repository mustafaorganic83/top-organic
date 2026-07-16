<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\Attendance;
use App\Models\Geofence;
use App\Modules\HR\Data\HrContext;
use App\Modules\HR\Exceptions\HrException;
use App\Modules\HR\Services\Concerns\GuardsHrWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Attendance check-in/out with GPS and photo support and geofence validation. */
final class AttendanceService
{
    use GuardsHrWrites;

    /** @return Collection<int, Attendance> */
    public function list(HrContext $context, string $employeeId, ?string $from = null, ?string $to = null): Collection
    {
        return Attendance::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('employee_id', $employeeId)
            ->when($from, fn ($q) => $q->where('work_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('work_date', '<=', $to))
            ->orderByDesc('work_date')
            ->get();
    }

    public function find(HrContext $context, string $id): Attendance
    {
        return Attendance::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)->whereKey($id)->first()
            ?? throw HrException::notFound('Attendance record not found.');
    }

    /** @param array<string, mixed> $data Check-in action */
    public function checkIn(HrContext $context, array $data): Attendance
    {
        return DB::transaction(function () use ($context, $data): Attendance {
            $employeeId = (string) $data['employee_id'];
            $workDate = $data['work_date'] ?? now()->toDateString();

            $existing = Attendance::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->where('employee_id', $employeeId)
                ->where('work_date', $workDate)->first();
            if ($existing) {
                throw HrException::conflict(HrException::IN_USE, 'Employee already checked in for this date.');
            }

            $lat = isset($data['lat']) ? (float) $data['lat'] : null;
            $lng = isset($data['lng']) ? (float) $data['lng'] : null;
            $withinFence = $lat !== null && $lng !== null
                ? $this->verifyGeofence($context, $lat, $lng)
                : false;

            $record = Attendance::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'employee_id' => $employeeId,
                'work_date' => $workDate,
                'check_in_at' => now(),
                'check_in_lat' => $lat,
                'check_in_lng' => $lng,
                'photo_path' => $data['photo'] ?? null,
                'within_geofence' => $withinFence,
                'status' => 'checked_in',
                'worked_hours' => 0,
                'lock_version' => 0,
            ]);
            $this->audit($context, 'attendance', $record->id, 'checked_in');

            return $record;
        }, 3);
    }

    /** @param array<string, mixed> $data Check-out action */
    public function checkOut(HrContext $context, string $id, array $data): Attendance
    {
        return DB::transaction(function () use ($context, $id, $data): Attendance {
            $record = Attendance::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw HrException::notFound('Attendance record not found.');
            $this->assertVersion($record->lock_version, (int) $data['expected_version']);
            if ($record->check_out_at !== null) {
                throw HrException::invalidState('Already checked out.');
            }
            $checkOut = now();
            $hours = $record->check_in_at
                ? round($record->check_in_at->diffInMinutes($checkOut) / 60, 2)
                : 0;
            $record->check_out_at = $checkOut;
            $record->check_out_lat = $data['lat'] ?? null;
            $record->check_out_lng = $data['lng'] ?? null;
            $record->status = 'checked_out';
            $record->worked_hours = $hours;
            $record->lock_version++;
            $record->save();
            $this->audit($context, 'attendance', $record->id, 'checked_out');

            return $record->refresh();
        }, 3);
    }

    private function verifyGeofence(HrContext $context, float $lat, float $lng): bool
    {
        $fences = Geofence::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->where('is_active', true)->get();
        foreach ($fences as $fence) {
            $dist = $this->haversine($lat, $lng, (float) $fence->center_lat, (float) $fence->center_lng);
            if ($dist <= $fence->radius_meters) {
                return true;
            }
        }

        return false;
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
