<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Application\Documents\GetAuthorizedDocument;
use App\Application\Documents\ListAuthorizedDocuments;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Documents\Enums\DocumentCategory;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DocumentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_capability_and_exact_document_grant_are_both_required(): void
    {
        [$user, $business, $membership] = $this->context();
        $document = $this->document($business, $membership);

        $this->allowDocument(
            $business,
            $membership,
            $document,
            DocumentAccessRight::View,
        );

        $this->assertNull(
            $this->resolveAuthorizedDocumentForTest()->execute(
                $user,
                $business,
                (string) $document->getKey(),
            ),
        );

        $this->allowSystem(
            $business,
            $membership,
            'records.view',
        );

        $resolved = $this->resolveAuthorizedDocumentForTest()->execute(
            $user,
            $business,
            (string) $document->getKey(),
        );

        $this->assertNotNull($resolved);
        $this->assertSame(
            (string) $document->getKey(),
            (string) $resolved->getKey(),
        );
    }

    public function test_document_deny_wins_over_matching_allow(): void
    {
        [$user, $business, $membership] = $this->context();
        $document = $this->document($business, $membership);

        $this->allowSystem(
            $business,
            $membership,
            'records.view',
        );

        $this->allowDocument(
            $business,
            $membership,
            $document,
            DocumentAccessRight::View,
        );

        DocumentAccessGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'document_id' => $document->getKey(),
            'right' => DocumentAccessRight::View,
            'effect' => 'deny',
        ]);

        $this->assertNull(
            $this->resolveAuthorizedDocumentForTest()->execute(
                $user,
                $business,
                (string) $document->getKey(),
            ),
        );
    }

    public function test_cross_business_and_nonexistent_ids_fail_closed_equally(): void
    {
        [$user, $businessA, $membershipA] = $this->context();
        [, $businessB, $membershipB] = $this->context();

        $this->allowSystem(
            $businessA,
            $membershipA,
            'records.view',
        );

        $foreign = $this->document(
            $businessB,
            $membershipB,
        );

        $crossBusiness = $this->resolveAuthorizedDocumentForTest()->execute(
            $user,
            $businessA,
            (string) $foreign->getKey(),
        );

        $nonexistent = $this->resolveAuthorizedDocumentForTest()->execute(
            $user,
            $businessA,
            (string) Str::uuid7(),
        );

        $this->assertNull($crossBusiness);
        $this->assertNull($nonexistent);
        $this->assertSame($crossBusiness, $nonexistent);
    }

    public function test_authorized_list_excludes_ungranted_and_explicitly_denied_documents(): void
    {
        [$user, $business, $membership] = $this->context();

        $this->allowSystem(
            $business,
            $membership,
            'records.view',
        );

        $visible = $this->document(
            $business,
            $membership,
            'Visible',
        );

        $denied = $this->document(
            $business,
            $membership,
            'Denied',
        );

        $ungranted = $this->document(
            $business,
            $membership,
            'Ungrant',
        );

        $this->allowDocument(
            $business,
            $membership,
            $visible,
            DocumentAccessRight::View,
        );

        $this->allowDocument(
            $business,
            $membership,
            $denied,
            DocumentAccessRight::View,
        );

        DocumentAccessGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'document_id' => $denied->getKey(),
            'right' => DocumentAccessRight::View,
            'effect' => 'deny',
        ]);

        $documents = $this->app
            ->make(ListAuthorizedDocuments::class)
            ->execute($user, $business);

        $this->assertNotNull($documents);

        $ids = $documents
            ->map(
                static fn (Document $document): string => (string) $document->getKey(),
            )
            ->all();

        $this->assertSame(
            [(string) $visible->getKey()],
            $ids,
        );

        $this->assertNotContains(
            (string) $ungranted->getKey(),
            $ids,
        );
    }

    /**
     * @return array{User, Business, Membership}
     */
    private function context(): array
    {
        $user = User::query()->create([
            'email' => Str::uuid7().'@example.test',
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        $business = Business::query()->create([
            'name' => 'Document Auth '.Str::uuid7(),
            'origin_type' => BusinessOriginType::StartedThroughPbr,
            'business_stage' => BusinessStage::Idea,
            'setup_phase' => SetupPhase::Formation,
            'workspace_status' => WorkspaceStatus::Active,
            'base_currency' => 'USD',
        ]);

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => MembershipAccessStatus::Active,
        ]);

        return [$user, $business, $membership];
    }

    private function document(
        Business $business,
        Membership $membership,
        string $title = 'Private Document',
    ): Document {
        return Document::query()->create([
            'business_id' => $business->getKey(),
            'title' => $title,
            'category' => DocumentCategory::CorporateLegal,
            'created_by_membership_id' => $membership->getKey(),
        ]);
    }

    private function allowSystem(
        Business $business,
        Membership $membership,
        string $key,
    ): void {
        $permission = Permission::query()->create([
            'key' => $key,
        ]);

        PermissionGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
            'effect' => PermissionEffect::Allow,
        ]);
    }

    private function allowDocument(
        Business $business,
        Membership $membership,
        Document $document,
        DocumentAccessRight $right,
    ): void {
        DocumentAccessGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'document_id' => $document->getKey(),
            'right' => $right,
            'effect' => 'allow',
        ]);
    }

    private function resolveAuthorizedDocumentForTest(): GetAuthorizedDocument
    {
        return $this->app->make(
            GetAuthorizedDocument::class,
        );
    }

    public function test_document_mutations_reuse_round3_occurrence_infrastructure(): void
    {
        foreach ([
            base_path('app/Application/Documents/UploadDocument.php'),
            base_path('app/Application/Documents/UploadDocumentVersion.php'),
        ] as $path) {
            $source = file_get_contents($path);

            $this->assertIsString($source);
            $this->assertStringContainsString(
                'RecordBusinessOccurrence',
                $source,
            );
            $this->assertStringContainsString(
                'SafeAuditMetadata::from',
                $source,
            );
            $this->assertStringNotContainsString(
                'AuditEvent::query()->create',
                $source,
            );
            $this->assertStringNotContainsString(
                'BusinessEvent::query()->create',
                $source,
            );
        }
    }
}
