<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\Branch;
use App\Models\DomainOutboxEvent;
use App\Models\Tenant;
use App\Modules\Sales\Services\OutboxProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OutboxProcessorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'outbox', 'name' => 'Outbox']);
        $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main']);
    }

    public function test_claim_and_acknowledge_publishes_due_events_only(): void
    {
        $due = $this->event('due');
        $this->event('future', now()->addHour());

        $processor = app(OutboxProcessor::class);
        $summary = $processor->process($this->tenant->id, null, fn () => null);

        $this->assertSame(1, $summary['claimed']);
        $this->assertSame(1, $summary['published']);
        $this->assertDatabaseHas('domain_outbox_events', ['id' => $due->id, 'state' => 'published']);
        $this->assertDatabaseHas('domain_outbox_events', ['state' => 'pending']);
    }

    public function test_failure_backs_off_then_dead_letters_after_max_attempts(): void
    {
        $event = $this->event('poison');
        $event->update(['attempt_count' => (int) config('sales.sync.outbox.max_attempts', 8) - 1]);

        $processor = app(OutboxProcessor::class);
        $summary = $processor->process($this->tenant->id, null, function (): void {
            throw new RuntimeException('publish failed');
        });

        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $summary['dead_lettered']);
        $this->assertDatabaseHas('domain_outbox_events', ['id' => $event->id, 'state' => 'dead_lettered']);
    }

    public function test_transient_failure_reschedules_for_retry(): void
    {
        $event = $this->event('retryable');
        $processor = app(OutboxProcessor::class);
        $processor->process($this->tenant->id, null, function (): void {
            throw new RuntimeException('transient');
        });

        $reloaded = $event->fresh();
        $this->assertSame('pending', $reloaded->state);
        $this->assertSame(1, $reloaded->attempt_count);
        $this->assertTrue($reloaded->available_at->isFuture());
    }

    public function test_stale_claim_is_recovered_after_worker_timeout(): void
    {
        $event = $this->event('stale-claim');
        DomainOutboxEvent::withoutGlobalScopes()->whereKey($event->id)->update([
            'state' => 'claimed', 'updated_at' => now()->subMinutes(10),
        ]);

        $summary = app(OutboxProcessor::class)->process($this->tenant->id, null, fn () => null);

        $this->assertSame(1, $summary['published']);
        $this->assertDatabaseHas('domain_outbox_events', ['id' => $event->id, 'state' => 'published']);
    }

    private function event(string $type, ?\DateTimeInterface $availableAt = null): DomainOutboxEvent
    {
        return DomainOutboxEvent::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'aggregate_type' => 'order',
            'aggregate_id' => (string) Str::ulid(), 'aggregate_sequence' => 1, 'event_type' => $type,
            'event_version' => 1, 'payload' => ['type' => $type], 'idempotency_key' => (string) Str::ulid(),
            'state' => 'pending', 'available_at' => $availableAt ?? now()->subMinute(),
        ]);
    }
}
