<?php

declare(strict_types=1);

namespace Tests\Feature\Conflict;

require_once __DIR__.'/F6EConflictPolicyTest.php';

use App\Application\Conflict\ConflictCaseWorkflow;
use App\Application\Conflict\ConflictRecordVisibility;
use App\Application\Conflict\GetConflictWorkspace;

final class F6EConflictPrivacyTest extends F6EConflictTestCase
{
    public function test_recorded_party_cannot_infer_case_existence_without_explicit_access(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context);

        $this->grantCapability(
            $context['business'],
            $context['party'],
            'conflict.view',
        );

        $payload = $this->app->make(GetConflictWorkspace::class)
            ->execute(
                $context['party_user'],
                $context['business'],
                $opened['id'],
            );

        self::assertNotNull($payload);
        self::assertSame(0, $payload['counts']['visible_cases']);
        self::assertSame(0, $payload['counts']['open']);
        self::assertSame([], $payload['cases']);
        self::assertNull($payload['selected_case']);
    }

    public function test_explicit_case_access_reveals_only_that_authorized_case(): void
    {
        $context = $this->context();
        $caseA = $this->openCase($context);
        $caseB = $this->openCase($context);

        $this->grantCapability(
            $context['business'],
            $context['party'],
            'conflict.view',
        );

        self::assertTrue(
            $this->app->make(ConflictCaseWorkflow::class)->grantAccess(
                $context['user'],
                $context['business'],
                $caseA['id'],
                [(string) $context['party']->getKey()],
                false,
            ),
        );

        $payload = $this->app->make(GetConflictWorkspace::class)
            ->execute(
                $context['party_user'],
                $context['business'],
                $caseA['id'],
            );

        self::assertNotNull($payload);
        self::assertSame(1, $payload['counts']['visible_cases']);
        self::assertCount(1, $payload['cases']);
        self::assertSame($caseA['id'], $payload['cases'][0]['id']);
        self::assertNotSame($caseB['id'], $payload['cases'][0]['id']);
        self::assertSame($caseA['id'], $payload['selected_case']['id']);
    }

    public function test_explicit_deny_wins_and_removes_case_from_counts(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context);
        $workflow = $this->app->make(ConflictCaseWorkflow::class);

        $this->grantCapability(
            $context['business'],
            $context['party'],
            'conflict.view',
        );

        self::assertTrue($workflow->grantAccess(
            $context['user'],
            $context['business'],
            $opened['id'],
            [(string) $context['party']->getKey()],
            false,
        ));

        self::assertTrue($workflow->denyAccess(
            $context['user'],
            $context['business'],
            $opened['id'],
            [(string) $context['party']->getKey()],
        ));

        self::assertFalse(
            $this->app->make(ConflictRecordVisibility::class)->canView(
                $context['party_user'],
                $context['business'],
                $opened['id'],
            ),
        );

        $payload = $this->app->make(GetConflictWorkspace::class)
            ->execute(
                $context['party_user'],
                $context['business'],
                $opened['id'],
            );

        self::assertNotNull($payload);
        self::assertSame(0, $payload['counts']['visible_cases']);
        self::assertNull($payload['selected_case']);
    }

    public function test_cross_business_case_id_fails_closed(): void
    {
        $context = $this->context();
        $foreign = $this->context();
        $foreignCase = $this->openCase($foreign);

        self::assertFalse(
            $this->app->make(ConflictRecordVisibility::class)->canView(
                $context['user'],
                $context['business'],
                $foreignCase['id'],
            ),
        );

        $payload = $this->app->make(GetConflictWorkspace::class)
            ->execute(
                $context['user'],
                $context['business'],
                $foreignCase['id'],
            );

        self::assertNotNull($payload);
        self::assertNull($payload['selected_case']);
    }
}
