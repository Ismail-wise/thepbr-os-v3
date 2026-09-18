<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Application\Audit\AppendAuditEvent;
use App\Domain\Audit\Enums\AuditActorType;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class AuditEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_system_and_service_actors_append_safe_business_scoped_events(): void
    {
        $business = $this->business();
        $targetId = (string) Str::uuid7();

        foreach ([
            AuditActor::user((string) Str::uuid7()),
            AuditActor::system(),
            AuditActor::serviceIntegration('partner-dynamics'),
        ] as $actor) {
            $event = $this->app->make(AppendAuditEvent::class)->append(
                $business,
                $actor,
                'records.test.changed',
                new OccurrenceTarget(
                    (string) $business->getKey(),
                    'test_record',
                    $targetId,
                ),
                SafeAuditMetadata::from(['revision' => 1]),
                now(),
                (string) Str::uuid7(),
            );

            $this->assertSame(
                (string) $business->getKey(),
                (string) $event->business_id,
            );
            $this->assertSame($actor->type, $event->actor_type);
            $this->assertSame($actor->identifier, $event->actor_identifier);
        }

        $this->assertDatabaseCount('audit_events', 3);
    }

    public function test_cross_business_target_is_rejected(): void
    {
        $business = $this->business();

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(AppendAuditEvent::class)->append(
            $business,
            AuditActor::system(),
            'records.test.changed',
            new OccurrenceTarget(
                (string) Str::uuid7(),
                'test_record',
                (string) Str::uuid7(),
            ),
            SafeAuditMetadata::from(),
            now(),
        );
    }

    public function test_audit_rows_are_database_immutable(): void
    {
        $business = $this->business();

        $event = $this->app->make(AppendAuditEvent::class)->append(
            $business,
            AuditActor::system(),
            'records.test.changed',
            new OccurrenceTarget(
                (string) $business->getKey(),
                'test_record',
                (string) Str::uuid7(),
            ),
            SafeAuditMetadata::from(),
            now(),
        );

        $storedMetadata = DB::selectOne(
            'SELECT jsonb_typeof(metadata) AS metadata_type FROM audit_events WHERE id = ?',
            [$event->getKey()],
        );

        $this->assertSame('object', $storedMetadata?->metadata_type);
        $this->assertSame([], $event->fresh()->metadata);

        $this->assertDatabaseRejects(
            fn () => DB::table('audit_events')
                ->where('id', $event->getKey())
                ->update(['action' => 'records.test.rewritten']),
        );

        $this->assertDatabaseRejects(
            fn () => DB::table('audit_events')
                ->where('id', $event->getKey())
                ->delete(),
        );
    }

    public function test_actor_enum_contains_exact_supported_types(): void
    {
        $this->assertSame([
            'user',
            'system',
            'service_integration',
        ], array_map(
            static fn (AuditActorType $type): string => $type->value,
            AuditActorType::cases(),
        ));
    }

    private function business(): Business
    {
        return Business::query()->create([
            'name' => 'Audit Business '.Str::uuid7(),
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
