<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use App\Application\Governance\MakeGovernedRecordEffective;
use App\Application\Governance\ResolveFormationAuthority;
use App\Application\Governance\SignGovernanceDocument;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class F3SourceBoundaryTest extends TestCase
{
    public function test_delegation_and_emergency_records_do_not_silently_grant_current_authority(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(ResolveFormationAuthority::class))->getFileName(),
        );

        $this->assertIsString($source);
        $this->assertStringNotContainsString('GovernanceDelegation', $source);
        $this->assertStringNotContainsString('EmergencyAuthorityGrant', $source);
    }

    public function test_signature_workflow_does_not_accept_an_arbitrary_signer_identity(): void
    {
        $method = new ReflectionMethod(SignGovernanceDocument::class, 'execute');

        $parameters = array_map(
            static fn ($parameter): string => $parameter->getName(),
            $method->getParameters(),
        );

        $this->assertNotContains('membershipId', $parameters);
        $this->assertNotContains('signatureParticipantId', $parameters);
        $this->assertNotContains('signerUserId', $parameters);
    }

    public function test_effectivity_is_routed_through_the_formal_record_transition_service(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(MakeGovernedRecordEffective::class))->getFileName(),
        );

        $this->assertIsString($source);
        $this->assertStringContainsString('TransitionFormalRecordVersion', $source);
        $this->assertStringContainsString('FormalRecordState::Effective', $source);
        $this->assertStringContainsString('required_signature_completed', $source);
    }
}
