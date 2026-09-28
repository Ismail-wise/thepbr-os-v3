<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\AI\BuildAuthorizedAiContext;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Documents\Enums\DocumentCategory;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F7AiPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_context_reuses_live_document_authorization_and_revocation(): void
    {
        [$user, $business, $membership] = $this->workspace(
            'ai-document-revoke',
        );

        $document = Document::query()->create([
            'business_id' => $business->getKey(),
            'title' => 'BlueLantern Restricted Contract',
            'category' => DocumentCategory::AgreementsContracts,
            'created_by_membership_id' => $membership->getKey(),
        ]);

        $grant = DocumentAccessGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'document_id' => $document->getKey(),
            'right' => DocumentAccessRight::View,
            'effect' => PermissionEffect::Allow->value,
        ]);

        $builder = $this->app->make(BuildAuthorizedAiContext::class);

        $before = $builder->execute(
            $user,
            $business,
            'BlueLantern',
        );

        self::assertNotNull($before);
        self::assertCount(1, $before['authorized_sources']);
        self::assertSame(
            'document',
            $before['authorized_sources'][0]['source_type'],
        );
        self::assertSame(
            'BlueLantern Restricted Contract',
            $before['authorized_sources'][0]['title'],
        );

        $grant->effect = PermissionEffect::Deny->value;
        $grant->save();

        $after = $builder->execute(
            $user,
            $business,
            'BlueLantern',
        );

        self::assertNotNull($after);
        self::assertSame([], $after['authorized_sources']);

        $serialized = json_encode($after, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(
            'BlueLantern Restricted Contract',
            $serialized,
        );
        self::assertStringNotContainsString(
            (string) $document->getKey(),
            $serialized,
        );
    }

    public function test_ai_context_rechecks_source_module_capability_after_revocation(): void
    {
        [$user, $business, $membership] = $this->workspace(
            'ai-partner-revoke',
        );

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

        $builder = $this->app->make(BuildAuthorizedAiContext::class);

        $before = $builder->execute(
            $user,
            $business,
            'CopperFalcon',
        );

        self::assertNotNull($before);
        self::assertCount(1, $before['authorized_sources']);
        self::assertSame(
            $partnerId,
            $before['authorized_sources'][0]['source_id'],
        );

        $permission = Permission::query()
            ->where('key', CapabilityCatalog::PARTNERS_VIEW)
            ->sole();

        PermissionGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
            'effect' => PermissionEffect::Deny,
        ]);

        $after = $builder->execute(
            $user,
            $business,
            'CopperFalcon',
        );

        self::assertNotNull($after);
        self::assertSame([], $after['authorized_sources']);

        $serialized = json_encode($after, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(
            'CopperFalcon Partner',
            $serialized,
        );
        self::assertStringNotContainsString(
            $partnerId,
            $serialized,
        );
    }

    public function test_restricted_domain_is_excluded_even_when_normal_view_permission_exists(): void
    {
        [$user, $business] = $this->workspace(
            'ai-restricted-domain',
        );

        config()->set('pbr_ai.restricted_domains.conflict', false);

        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'conflict_resolution_policy',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'SableTiger restricted conflict policy.',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => null,
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('7', 64),
            'frozen_at' => null,
        ]);

        $context = $this->app
            ->make(BuildAuthorizedAiContext::class)
            ->execute(
                $user,
                $business,
                'SableTiger',
            );

        self::assertNotNull($context);
        self::assertSame([], $context['authorized_sources']);

        $serialized = json_encode($context, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(
            'SableTiger',
            $serialized,
        );
        self::assertStringNotContainsString(
            (string) $family->getKey(),
            $serialized,
        );
        self::assertStringNotContainsString(
            (string) $version->getKey(),
            $serialized,
        );
    }

    public function test_user_entered_burmese_data_is_preserved_verbatim_in_ai_context(): void
    {
        [$user, $business] = $this->workspace(
            'ai-verbatim',
        );

        $name = 'မူရင်း မြန်မာ Partner အမည်';

        DB::table('partners')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'display_name' => $name,
            'legal_name' => null,
            'email' => null,
            'status' => 'active',
            'notes' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $context = $this->app
            ->make(BuildAuthorizedAiContext::class)
            ->execute(
                $user,
                $business,
                'မြန်မာ Partner',
            );

        self::assertNotNull($context);
        self::assertCount(1, $context['authorized_sources']);
        self::assertSame(
            $name,
            $context['authorized_sources'][0]['title'],
        );
    }

    /**
     * @return array{User,Business,Membership}
     */
    private function workspace(string $prefix): array
    {
        $business = Business::query()->create([
            'name' => 'F7 AI Privacy '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        $user = User::query()->create([
            'email' => $prefix.'-'.Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => 'active',
            'password_changed_at' => now(),
        ]);

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        $this->app
            ->make(ProvisionStandardAccessProfiles::class)
            ->execute($business, $membership);

        return [$user, $business, $membership];
    }
}
