<?php

declare(strict_types=1);

namespace Tests\Feature\Partnership;

use App\Application\Governance\MakeGovernedRecordEffective;
use App\Application\Governance\PrepareGovernedRecordForEffect;
use App\Application\Partnership\OwnershipGovernanceWorkflow;
use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\CreateProposal;
use App\Application\Records\FreezeProposalVersion;
use App\Application\Records\SubmitRecordVersionForReview;
use App\Application\Records\TransitionFormalRecordVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

final class F5OwnershipGovernanceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_governance_submission_table_and_guard_exist(): void
    {
        self::assertTrue(
            DB::getSchemaBuilder()->hasTable(
                'ownership_governance_submissions',
            ),
        );

        $triggerCount = (int) DB::selectOne(
            <<<'SQL'
SELECT COUNT(*)::int AS count
FROM pg_trigger
WHERE tgname = 'ownership_governance_submissions_guard'
  AND NOT tgisinternal
SQL
        )->count;

        self::assertSame(1, $triggerCount);
    }

    public function test_ownership_orchestrator_reuses_f2_and_f3_foundations(): void
    {
        $constructor = (new ReflectionClass(
            OwnershipGovernanceWorkflow::class,
        ))->getConstructor();

        self::assertNotNull($constructor);

        $types = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            self::assertNotNull($type);

            $types[] = $type->getName();
        }

        foreach ([
            CreateFormalRecordFamily::class,
            CreateDraftRecordVersion::class,
            SubmitRecordVersionForReview::class,
            TransitionFormalRecordVersion::class,
            CreateProposal::class,
            FreezeProposalVersion::class,
            PrepareGovernedRecordForEffect::class,
            MakeGovernedRecordEffective::class,
        ] as $required) {
            self::assertContains($required, $types);
        }
    }

    public function test_ownership_orchestrator_does_not_reimplement_governance_authority(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(
                OwnershipGovernanceWorkflow::class,
            ))->getFileName(),
        );

        self::assertIsString($source);

        self::assertStringNotContainsString(
            "DB::table('approvals')->insert",
            $source,
        );

        self::assertStringNotContainsString(
            "DB::table('votes')->insert",
            $source,
        );

        self::assertStringNotContainsString(
            "DB::table('authority_snapshots')->insert",
            $source,
        );

        self::assertStringNotContainsString(
            "DB::table('decisions')->insert",
            $source,
        );

        self::assertStringContainsString(
            "'ownership_approval'",
            $source,
        );

        self::assertStringContainsString(
            'PrepareGovernedRecordForEffect',
            $source,
        );

        self::assertStringContainsString(
            'MakeGovernedRecordEffective',
            $source,
        );
    }

    public function test_effective_register_remains_unique_per_business(): void
    {
        $index = DB::selectOne(
            <<<'SQL'
SELECT indexdef
FROM pg_indexes
WHERE indexname =
    'ownership_one_effective_version_per_business'
SQL
        );

        self::assertNotNull($index);
        self::assertStringContainsString(
            "WHERE ((status)::text = 'effective'::text)",
            (string) $index->indexdef,
        );
    }
}
