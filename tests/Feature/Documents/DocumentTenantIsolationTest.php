<?php

namespace Tests\Feature\Documents;

use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Documents\Enums\DocumentCategory;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DocumentTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_and_grant_relationships_preserve_business_membership_scope(): void
    {
        [$business, $membership] = $this->createBusinessMembership(
            'document-owner@example.com',
            'Document Business',
        );

        $document = Document::query()->create([
            'business_id' => $business->id,
            'title' => 'Partnership Agreement',
            'category' => DocumentCategory::AgreementsContracts,
            'created_by_membership_id' => $membership->id,
        ])->refresh();

        $grant = DocumentAccessGrant::query()->create([
            'business_id' => $business->id,
            'membership_id' => $membership->id,
            'document_id' => $document->id,
            'right' => DocumentAccessRight::View,
            'effect' => 'allow',
        ])->refresh();

        $this->assertSame($business->id, $document->business->id);
        $this->assertSame($membership->id, $document->createdByMembership->id);
        $this->assertSame(DocumentCategory::AgreementsContracts, $document->category);

        $this->assertSame($business->id, $grant->business->id);
        $this->assertSame($membership->id, $grant->membership->id);
        $this->assertSame($document->id, $grant->document->id);
        $this->assertSame(DocumentAccessRight::View, $grant->right);
        $this->assertSame('allow', $grant->effect);
    }

    public function test_document_rejects_creator_membership_from_another_business(): void
    {
        [$businessA] = $this->createBusinessMembership(
            'business-a@example.com',
            'Business A',
        );

        [, $membershipB] = $this->createBusinessMembership(
            'business-b@example.com',
            'Business B',
        );

        $this->expectDatabaseRejection(function () use ($businessA, $membershipB): void {
            Document::query()->create([
                'business_id' => $businessA->id,
                'title' => 'Cross Business Document',
                'category' => DocumentCategory::CorporateLegal,
                'created_by_membership_id' => $membershipB->id,
            ]);
        });
    }

    public function test_document_access_grant_rejects_cross_business_membership(): void
    {
        [$businessA, $membershipA] = $this->createBusinessMembership(
            'grant-a@example.com',
            'Grant Business A',
        );

        [, $membershipB] = $this->createBusinessMembership(
            'grant-b@example.com',
            'Grant Business B',
        );

        $document = Document::query()->create([
            'business_id' => $businessA->id,
            'title' => 'Private Document',
            'category' => DocumentCategory::GovernanceDecisions,
            'created_by_membership_id' => $membershipA->id,
        ]);

        $this->expectDatabaseRejection(function () use (
            $businessA,
            $membershipB,
            $document,
        ): void {
            DocumentAccessGrant::query()->create([
                'business_id' => $businessA->id,
                'membership_id' => $membershipB->id,
                'document_id' => $document->id,
                'right' => DocumentAccessRight::Manage,
                'effect' => 'allow',
            ]);
        });
    }

    private function expectDatabaseRejection(callable $operation): void
    {
        try {
            DB::transaction(function () use ($operation): void {
                $operation();
            });

            $this->fail('Expected PostgreSQL to reject the invalid tenant relationship.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @return array{Business, Membership}
     */
    private function createBusinessMembership(
        string $email,
        string $businessName,
    ): array {
        $user = User::query()->create([
            'email' => $email,
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Provisioned,
            'password_changed_at' => now(),
        ]);

        $business = Business::query()->create([
            'name' => $businessName,
            'origin_type' => BusinessOriginType::StartedThroughPbr,
            'business_stage' => BusinessStage::Idea,
            'setup_phase' => null,
            'workspace_status' => WorkspaceStatus::Active,
            'base_currency' => 'USD',
        ]);

        $membership = Membership::query()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'access_status' => MembershipAccessStatus::Active,
        ]);

        return [$business, $membership];
    }
}
