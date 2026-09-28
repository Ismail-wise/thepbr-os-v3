<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

require_once __DIR__.'/../Conflict/F6EConflictPolicyTest.php';

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Conflict\ConflictRecordVisibility;
use App\Application\Search\GlobalSearch;
use App\Application\Search\SearchIndexProjector;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Conflict\F6EConflictTestCase;

final class F7SearchPrivacyTest extends F6EConflictTestCase
{
    public function test_stale_document_index_row_cannot_bypass_later_document_access_revocation(): void
    {
        [$user, $business, $membership] = $this->ownerWorkspace(
            'search-document-revoke',
        );

        $document = Document::query()->create([
            'business_id' => $business->getKey(),
            'title' => 'BlueLantern Restricted Contract',
            'category' => 'agreements_contracts',
            'created_by_membership_id' => $membership->getKey(),
        ]);

        $grant = DocumentAccessGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'document_id' => $document->getKey(),
            'right' => DocumentAccessRight::View,
            'effect' => PermissionEffect::Allow->value,
        ]);

        $projector = $this->app->make(SearchIndexProjector::class);
        self::assertGreaterThan(0, $projector->rebuildBusiness($business));

        self::assertDatabaseHas('search_index_entries', [
            'business_id' => $business->getKey(),
            'source_type' => 'document',
            'source_id' => $document->getKey(),
            'title' => 'BlueLantern Restricted Contract',
        ]);

        $before = $this->app->make(GlobalSearch::class)->execute(
            $user,
            $business,
            'BlueLantern',
        );

        self::assertNotNull($before);
        self::assertSame(1, $before['count']);

        $grant->effect = PermissionEffect::Deny->value;
        $grant->save();

        self::assertDatabaseHas('search_index_entries', [
            'business_id' => $business->getKey(),
            'source_type' => 'document',
            'source_id' => $document->getKey(),
        ]);

        $after = $this->app->make(GlobalSearch::class)->execute(
            $user,
            $business,
            'BlueLantern',
        );

        self::assertNotNull($after);
        self::assertSame(0, $after['count']);
        self::assertSame([], $after['items']);
        self::assertSame([], $after['suggestions']);

        self::assertStringNotContainsString(
            'BlueLantern',
            json_encode(
                [
                    'items' => $after['items'],
                    'suggestions' => $after['suggestions'],
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function test_restricted_conflict_does_not_leak_existence_title_count_snippet_or_suggestion(): void
    {
        $context = $this->context();

        $this->grantCapability(
            $context['business'],
            $context['party'],
            CapabilityCatalog::SEARCH_VIEW,
        );
        $this->grantCapability(
            $context['business'],
            $context['party'],
            CapabilityCatalog::CONFLICT_VIEW,
        );

        $opened = $this->openCase($context);
        $caseId = $opened['id'];
        $caseNumber = (string) DB::table('conflict_cases')
            ->where('business_id', $context['business']->getKey())
            ->where('id', $caseId)
            ->value('case_number');

        $hidden = $this->app->make(GlobalSearch::class)->execute(
            $context['party_user'],
            $context['business'],
            $caseNumber,
        );

        self::assertNotNull($hidden);
        self::assertSame(0, $hidden['count']);
        self::assertSame([], $hidden['items']);
        self::assertSame([], $hidden['suggestions']);

        $hiddenPayload = json_encode(
            [
                'items' => $hidden['items'],
                'suggestions' => $hidden['suggestions'],
            ],
            JSON_THROW_ON_ERROR,
        );

        self::assertStringNotContainsString($caseNumber, $hiddenPayload);
        self::assertStringNotContainsString($caseId, $hiddenPayload);

        $visibility = $this->app->make(ConflictRecordVisibility::class);
        $visibility->grantRestrictedAccess(
            $context['business'],
            $caseId,
            [(string) $context['party']->getKey()],
            false,
        );

        $visible = $this->app->make(GlobalSearch::class)->execute(
            $context['party_user'],
            $context['business'],
            $caseNumber,
        );

        self::assertNotNull($visible);
        self::assertSame(1, $visible['count']);
        self::assertCount(1, $visible['items']);
        self::assertSame(
            'conflict_case',
            $visible['items'][0]['source_type'],
        );
        self::assertSame($caseId, $visible['items'][0]['source_id']);
        self::assertContains($caseNumber, $visible['suggestions']);

        $visibility->denyRestrictedAccess(
            $context['business'],
            $caseId,
            [(string) $context['party']->getKey()],
        );

        $revoked = $this->app->make(GlobalSearch::class)->execute(
            $context['party_user'],
            $context['business'],
            $caseNumber,
        );

        self::assertNotNull($revoked);
        self::assertSame(0, $revoked['count']);
        self::assertSame([], $revoked['items']);
        self::assertSame([], $revoked['suggestions']);

        $revokedPayload = json_encode(
            [
                'items' => $revoked['items'],
                'suggestions' => $revoked['suggestions'],
            ],
            JSON_THROW_ON_ERROR,
        );

        self::assertStringNotContainsString($caseNumber, $revokedPayload);
        self::assertStringNotContainsString($caseId, $revokedPayload);
    }

    public function test_restricted_conflict_formal_record_never_falls_back_to_generic_record_visibility(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context);
        $caseId = $opened['id'];

        foreach ([
            CapabilityCatalog::SEARCH_VIEW,
            CapabilityCatalog::CONFLICT_VIEW,
            CapabilityCatalog::RECORDS_VIEW,
        ] as $capability) {
            $this->grantCapability(
                $context['business'],
                $context['party'],
                $capability,
            );
        }

        $recordPermission = Permission::query()
            ->where('key', CapabilityCatalog::RECORDS_VIEW)
            ->sole();

        AccessPolicy::query()->create([
            'business_id' => $context['business']->getKey(),
            'membership_id' => $context['party']->getKey(),
            'permission_profile_id' => null,
            'permission_id' => $recordPermission->getKey(),
            'resource_type' => FormalRecordVersion::class,
            'effect' => PermissionEffect::Allow->value,
        ]);

        $family = FormalRecordFamily::query()->create([
            'business_id' => $context['business']->getKey(),
            'record_type' => 'conflict_settlement',
            'subject_type' => 'conflict_case',
            'subject_id' => $caseId,
        ]);

        $version = FormalRecordVersion::query()->create([
            'business_id' => $context['business']->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'SableTiger confidential settlement.',
            'created_by_user_id' => $context['user']->getKey(),
            'last_changed_by_user_id' => $context['user']->getKey(),
            'effective_from' => null,
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('8', 64),
            'frozen_at' => null,
        ]);

        $hidden = $this->app->make(GlobalSearch::class)->execute(
            $context['party_user'],
            $context['business'],
            'SableTiger',
        );

        self::assertNotNull($hidden);
        self::assertSame(0, $hidden['count']);
        self::assertSame([], $hidden['items']);
        self::assertSame([], $hidden['suggestions']);

        $visibility = $this->app->make(ConflictRecordVisibility::class);
        $visibility->grantRestrictedAccess(
            $context['business'],
            $caseId,
            [(string) $context['party']->getKey()],
            false,
        );

        $visible = $this->app->make(GlobalSearch::class)->execute(
            $context['party_user'],
            $context['business'],
            'SableTiger',
        );

        self::assertNotNull($visible);
        self::assertSame(1, $visible['count']);
        self::assertSame(
            'formal_record_version',
            $visible['items'][0]['source_type'],
        );
        self::assertSame(
            (string) $version->getKey(),
            $visible['items'][0]['source_id'],
        );

        $visibility->denyRestrictedAccess(
            $context['business'],
            $caseId,
            [(string) $context['party']->getKey()],
        );

        $revoked = $this->app->make(GlobalSearch::class)->execute(
            $context['party_user'],
            $context['business'],
            'SableTiger',
        );

        self::assertNotNull($revoked);
        self::assertSame(0, $revoked['count']);
        self::assertSame([], $revoked['items']);
        self::assertSame([], $revoked['suggestions']);
    }

    public function test_stale_index_cannot_bypass_search_capability_revocation(): void
    {
        $business = $this->searchBusiness('search-capability-revoke');
        [$user, $membership] = $this->member(
            $business,
            'search-capability-user',
        );

        $this->grantCapability(
            $business,
            $membership,
            CapabilityCatalog::SEARCH_VIEW,
        );
        $this->grantCapability(
            $business,
            $membership,
            CapabilityCatalog::PARTNERS_VIEW,
        );

        $permission = Permission::query()
            ->where('key', CapabilityCatalog::SEARCH_VIEW)
            ->sole();

        $searchGrant = PermissionGrant::query()
            ->where('business_id', $business->getKey())
            ->where('membership_id', $membership->getKey())
            ->where('permission_id', $permission->getKey())
            ->sole();

        $partnerId = (string) Str::uuid7();

        DB::table('partners')->insert([
            'id' => $partnerId,
            'business_id' => $business->getKey(),
            'display_name' => 'CopperFalcon Partner',
            'legal_name' => null,
            'email' => null,
            'status' => 'active',
            'notes' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->app->make(SearchIndexProjector::class)
            ->rebuildBusiness($business);

        self::assertDatabaseHas('search_index_entries', [
            'business_id' => $business->getKey(),
            'source_type' => 'partner',
            'source_id' => $partnerId,
        ]);

        $searchGrant->effect = PermissionEffect::Deny->value;
        $searchGrant->save();

        self::assertNull(
            $this->app->make(GlobalSearch::class)->execute(
                $user,
                $business,
                'CopperFalcon',
            ),
        );

        self::assertDatabaseHas('search_index_entries', [
            'business_id' => $business->getKey(),
            'source_type' => 'partner',
            'source_id' => $partnerId,
        ]);
    }

    /**
     * @return array{User,Business,Membership}
     */
    private function ownerWorkspace(string $prefix): array
    {
        $business = $this->searchBusiness($prefix);
        [$user, $membership] = $this->member($business, $prefix);

        $this->app
            ->make(ProvisionStandardAccessProfiles::class)
            ->execute($business, $membership);

        return [$user, $business, $membership];
    }

    private function searchBusiness(string $prefix): Business
    {
        return Business::query()->create([
            'name' => 'F7 Search Privacy '.$prefix.' '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);
    }
}
