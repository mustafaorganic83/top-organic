<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Services;

use App\Models\KdsTicket;
use App\Modules\Kitchen\Data\KitchenContext;
use Illuminate\Support\Carbon;

/**
 * Read-only kitchen analytics over the shared kds_tickets aggregate: headline
 * KPIs (throughput, average prep time, on-time rate, live load) and per-chef
 * performance. All queries are branch-scoped and bounded to a date window so
 * the board stays responsive.
 */
final class KitchenAnalyticsService
{
    /**
     * Headline KPIs for a window (defaults to the current business day).
     *
     * @return array<string, mixed>
     */
    public function kpis(KitchenContext $context, ?string $from, ?string $to, ?string $stationId): array
    {
        [$start, $end] = $this->window($from, $to);
        $base = fn () => KdsTicket::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->when($stationId !== null, fn ($q) => $q->where('kds_station_id', $stationId));

        $completed = $base()->whereBetween('served_at', [$start, $end])
            ->whereNotNull('served_at')->whereNotNull('prep_seconds')->get(['prep_seconds', 'sla_seconds']);
        $servedCount = $completed->count();
        $avgPrep = $servedCount === 0 ? null : (int) round($completed->avg('prep_seconds'));
        $onTime = $completed->filter(fn ($t) => $t->sla_seconds === null || $t->prep_seconds <= $t->sla_seconds)->count();

        $live = fn (string $state) => $base()->where('state', $state)->count();

        return [
            'window' => ['from' => $start->toISOString(), 'to' => $end->toISOString()],
            'served_count' => $servedCount,
            'average_prep_seconds' => $avgPrep,
            'on_time_rate' => $servedCount === 0 ? null : round($onTime / $servedCount, 4),
            'overdue_count' => $servedCount - $onTime,
            'live_load' => [
                'preparation' => $live('queued'),
                'cooking' => $live('in_progress'),
                'ready' => $live('ready'),
            ],
        ];
    }

    /**
     * Per-chef performance for a window.
     *
     * @return array<int, array<string, mixed>>
     */
    public function chefPerformance(KitchenContext $context, ?string $from, ?string $to): array
    {
        [$start, $end] = $this->window($from, $to);

        return KdsTicket::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->whereNotNull('chef_id')
            ->whereNotNull('served_at')
            ->whereBetween('served_at', [$start, $end])
            ->with('chef:id,name')
            ->get(['id', 'chef_id', 'prep_seconds', 'sla_seconds'])
            ->groupBy('chef_id')
            ->map(function ($tickets, $chefId): array {
                $withPrep = $tickets->whereNotNull('prep_seconds');
                $onTime = $withPrep->filter(fn ($t) => $t->sla_seconds === null || $t->prep_seconds <= $t->sla_seconds)->count();
                $count = $withPrep->count();

                return [
                    'chef_id' => (int) $chefId,
                    'chef_name' => $tickets->first()->chef?->name,
                    'served_count' => $tickets->count(),
                    'average_prep_seconds' => $count === 0 ? null : (int) round($withPrep->avg('prep_seconds')),
                    'on_time_rate' => $count === 0 ? null : round($onTime / $count, 4),
                ];
            })
            ->sortByDesc('served_count')
            ->values()->all();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(?string $from, ?string $to): array
    {
        $start = $from !== null ? Carbon::parse($from) : Carbon::now()->startOfDay();
        $end = $to !== null ? Carbon::parse($to) : Carbon::now()->endOfDay();

        return [$start, $end];
    }
}
