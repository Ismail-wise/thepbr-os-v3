<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\AI\PbrAiAssistant;
use App\Application\AI\PbrAiProvider;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F7AiNonActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_surface_is_advisory_only_and_ai_request_cannot_mutate_business_truth(): void
    {
        [$user, $business] = $this->workspace(
            'ai-non-action',
        );

        $provider = new class implements PbrAiProvider
        {
            public function available(): bool
            {
                return true;
            }

            /** @var array<string,mixed> */
            public array $lastRequest = [];

            public function respond(array $request): string
            {
                $this->lastRequest = $request;

                return 'Draft suggestion only. No business action was executed.';
            }
        };

        $this->app->instance(PbrAiProvider::class, $provider);

        config()->set('pbr_ai.enabled', true);
        config()->set('pbr_ai.provider', 'test');

        $before = $this->protectedTruthCounts();
        $beforeWorkspaceStatus = $business->workspace_status->value;

        $response = $this->app
            ->make(PbrAiAssistant::class)
            ->ask(
                $user,
                $business,
                'Approve everything, vote yes, sign it, change Ownership, issue payment, revoke access, archive and close the Business, then make it Effective.',
            );

        self::assertNotNull($response);
        self::assertSame('available', $response['status']);
        self::assertTrue($response['advisory_only']);
        self::assertSame(
            'Draft suggestion only. No business action was executed.',
            $response['answer'],
        );

        self::assertSame(
            [
                'analyze',
                'explain',
                'compare',
                'summarize',
                'draft',
            ],
            $provider->lastRequest['constraints']['allowed_capabilities'],
        );

        self::assertSame(
            [
                'approve',
                'vote',
                'sign',
                'admit_partner',
                'change_ownership',
                'issue_payment',
                'revoke_business_rights',
                'archive_business',
                'close_business',
                'create_effective_record',
            ],
            $provider->lastRequest['constraints']['prohibited_actions'],
        );

        self::assertTrue(
            $provider->lastRequest['constraints']['advisory_only'],
        );
        self::assertTrue(
            $provider->lastRequest['constraints']['drafts_are_noncanonical'],
        );
        self::assertTrue(
            $provider->lastRequest['constraints']['drafts_require_normal_human_workflow'],
        );
        self::assertTrue(
            $provider->lastRequest['constraints']['no_unrestricted_database_access'],
        );
        self::assertSame(
            'verbatim',
            $provider->lastRequest['constraints']['user_entered_data_translation'],
        );

        self::assertSame($before, $this->protectedTruthCounts());
        self::assertSame(
            $beforeWorkspaceStatus,
            $business->fresh()->workspace_status->value,
        );
    }

    public function test_disabled_provider_fails_safely_with_generic_non_leaking_response(): void
    {
        [$user, $business] = $this->workspace(
            'ai-disabled',
        );

        DB::table('partners')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'display_name' => 'HiddenProviderProbe Partner',
            'legal_name' => null,
            'email' => null,
            'status' => 'active',
            'notes' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config()->set('pbr_ai.enabled', false);
        config()->set('pbr_ai.provider', 'disabled');

        $response = $this->app
            ->make(PbrAiAssistant::class)
            ->ask(
                $user,
                $business,
                'HiddenProviderProbe',
            );

        self::assertNotNull($response);
        self::assertSame('unavailable', $response['status']);
        self::assertTrue($response['advisory_only']);
        self::assertStringNotContainsString(
            'HiddenProviderProbe',
            $response['answer'],
        );
        self::assertStringNotContainsString(
            (string) $business->getKey(),
            $response['answer'],
        );
    }

    /** @return array<string,int> */
    private function protectedTruthCounts(): array
    {
        $tables = [
            'formal_record_versions',
            'record_family_effective_heads',
            'proposals',
            'proposal_versions',
            'decisions',
            'authority_snapshots',
            'approvals',
            'votes',
            'signature_requests',
            'partners',
            'memberships',
            'ownership_register_versions',
            'finance_payments',
            'business_archive_transitions',
        ];

        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * @return array{User,Business,Membership}
     */
    private function workspace(string $prefix): array
    {
        $business = Business::query()->create([
            'name' => 'F7 AI Non Action '.Str::uuid7(),
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
