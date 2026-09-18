<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Application\Events\AppendBusinessEvent;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Events\ValueObjects\SafeBusinessEventPayload;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class BusinessEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_event_records_minimized_occurrence_without_mutating_canonical_record(): void
    {
        $business = $this->business();
        $aggregateId = (string) Str::uuid7();

        $event = $this->app->make(AppendBusinessEvent::class)->append(
            $business,
            'records.proposal_version.frozen',
            new OccurrenceTarget(
                (string) $business->getKey(),
                'proposal',
                $aggregateId,
                (string) Str::uuid7(),
            ),
            new OccurrenceTarget(
                (string) $business->getKey(),
                'proposal',
                $aggregateId,
            ),
            SafeBusinessEventPayload::from(['version_number' => 1]),
            now(),
            AuditActor::system(),
            (string) Str::uuid7(),
        );

        $this->assertSame(
            'records.proposal_version.frozen',
            $event->event_type,
        );
        $this->assertSame(['version_number' => 1], $event->payload);
        $this->assertDatabaseCount('business_events', 1);
    }

    public function test_cross_business_aggregate_or_visibility_target_is_rejected(): void
    {
        $business = $this->business();

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(AppendBusinessEvent::class)->append(
            $business,
            'records.proposal_version.frozen',
            new OccurrenceTarget(
                (string) $business->getKey(),
                'proposal',
                (string) Str::uuid7(),
            ),
            new OccurrenceTarget(
                (string) Str::uuid7(),
                'proposal',
                (string) Str::uuid7(),
            ),
            SafeBusinessEventPayload::from(),
            now(),
        );
    }

    public function test_business_event_rows_are_database_immutable(): void
    {
        $business = $this->business();

        $event = $this->app->make(AppendBusinessEvent::class)->append(
            $business,
            'records.proposal_version.frozen',
            new OccurrenceTarget(
                (string) $business->getKey(),
                'proposal',
                (string) Str::uuid7(),
            ),
            new OccurrenceTarget(
                (string) $business->getKey(),
                'proposal',
                (string) Str::uuid7(),
            ),
            SafeBusinessEventPayload::from(),
            now(),
        );

        $storedPayload = DB::selectOne(
            'SELECT jsonb_typeof(payload) AS payload_type FROM business_events WHERE id = ?',
            [$event->getKey()],
        );

        $this->assertSame('object', $storedPayload?->payload_type);
        $this->assertSame([], $event->fresh()->payload);

        $this->assertDatabaseRejects(
            fn () => DB::table('business_events')
                ->where('id', $event->getKey())
                ->update(['event_type' => 'records.rewritten']),
        );

        $this->assertDatabaseRejects(
            fn () => DB::table('business_events')
                ->where('id', $event->getKey())
                ->delete(),
        );
    }

    private function business(): Business
    {
        return Business::query()->create([
            'name' => 'Event Business '.Str::uuid7(),
            'origin_type' => BusinessOriginType::StartedThroughPbr->value,
            'business_stage' => BusinessStage::Idea->value,
            'setup_phase' => SetupPhase::Formation->value,
            'workspace_status' => WorkspaceStatus::Active->value,
            'base_currency' => 'USD',
        ]);
    }

    private function assertDatabaseRejects(callable $callback): void
    {
        DB::beginTransaction();

        try {
            $callback();
            DB::rollBack();
            $this->fail('Expected PostgreSQL to reject the write.');
        } catch (QueryException) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->addToAssertionCount(1);
        }
    }
}
