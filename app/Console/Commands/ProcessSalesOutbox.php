<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Sales\Services\OutboxProcessor;
use Illuminate\Console\Command;

/**
 * Drains a slice of pending domain outbox events for eventual publishing.
 * Intended to be scheduled; safe to run concurrently thanks to row-locked
 * claiming in the processor.
 */
class ProcessSalesOutbox extends Command
{
    protected $signature = 'sales:process-outbox {--tenant= : Restrict draining to a single tenant ID} {--limit= : Maximum events to claim in this run}';

    protected $description = 'Claim, publish, and retry pending Sales domain outbox events.';

    public function handle(OutboxProcessor $processor): int
    {
        $tenant = $this->option('tenant');
        $limit = $this->option('limit');
        $summary = $processor->process(
            is_string($tenant) && $tenant !== '' ? $tenant : null,
            is_numeric($limit) ? (int) $limit : null,
        );
        $this->info(sprintf(
            'Outbox drained: %d claimed, %d published, %d failed, %d dead-lettered.',
            $summary['claimed'], $summary['published'], $summary['failed'], $summary['dead_lettered'],
        ));

        return self::SUCCESS;
    }
}
