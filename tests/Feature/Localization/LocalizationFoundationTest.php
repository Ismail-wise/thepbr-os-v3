<?php

namespace Tests\Feature\Localization;

use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Identity\UserProfile;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class LocalizationFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'correct horse battery staple';

    /** @var list<string> */
    private array $redisSessionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->app['events']->listen(
            RequestHandled::class,
            function (RequestHandled $event): void {
                if ($event->request->hasSession()) {
                    $this->redisSessionIds[] = $event->request->session()->getId();
                }
            },
        );
    }

    protected function tearDown(): void
    {
        try {
            if (config('session.driver') === 'redis') {
                $redis = Redis::connection('default');
                $prefix = (string) config('session.prefix', '');

                foreach (array_unique($this->redisSessionIds) as $sessionId) {
                    $redis->del($prefix.$sessionId);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_guest_login_page_uses_english_ui_fallback(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('Auth/Login')
                    ->where('uiLanguageMode', LanguageMode::English->value)
                    ->where('workspace', null),
            );
    }

    public function test_authenticated_users_receive_their_saved_ui_language_mode(): void
    {
        foreach ([
            LanguageMode::English,
            LanguageMode::Myanmar,
            LanguageMode::Mixed,
        ] as $index => $languageMode) {
            $user = $this->createActiveUser(
                email: sprintf('mode-%d@example.test', $index),
                languageMode: $languageMode,
            );

            $this->actingAs($user)
                ->get('/')
                ->assertOk()
                ->assertInertia(
                    fn (Assert $page) => $page
                        ->component('AccountHome')
                        ->where('uiLanguageMode', $languageMode->value),
                );
        }
    }

    public function test_authenticated_user_without_profile_falls_back_to_english(): void
    {
        $user = User::query()->create([
            'email' => 'missing-profile@example.test',
            'password' => Hash::make(self::PASSWORD),
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('AccountHome')
                    ->where('uiLanguageMode', LanguageMode::English->value),
            );
    }

    public function test_settings_language_change_is_reflected_on_the_next_response(): void
    {
        $user = $this->createActiveUser(
            email: 'language-change@example.test',
            displayName: 'Language Owner',
            languageMode: LanguageMode::English,
            timezone: 'Asia/Yangon',
        );

        $this->actingAs($user)
            ->patch('/account/settings', [
                'display_name' => 'Language Owner',
                'language_mode' => LanguageMode::Mixed->value,
                'timezone' => 'Asia/Yangon',
            ])
            ->assertRedirect('/account/settings');

        $this->get('/account/settings')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('Account/Settings')
                    ->where('uiLanguageMode', LanguageMode::Mixed->value)
                    ->where('account.email', 'language-change@example.test')
                    ->where('account.profile.display_name', 'Language Owner')
                    ->where('account.profile.language_mode', LanguageMode::Mixed->value)
                    ->where('account.profile.timezone', 'Asia/Yangon')
                    ->where('languageOptions', ['en', 'my', 'mixed']),
            );

        $profile = UserProfile::query()->whereKey($user->id)->sole();

        $this->assertSame('Language Owner', $profile->display_name);
        $this->assertSame(LanguageMode::Mixed, $profile->language_mode);
        $this->assertSame('Asia/Yangon', $profile->timezone);
    }

    public function test_language_preference_does_not_leak_between_users(): void
    {
        $myanmarUser = $this->createActiveUser(
            email: 'myanmar-user@example.test',
            languageMode: LanguageMode::Myanmar,
        );

        $englishUser = $this->createActiveUser(
            email: 'english-user@example.test',
            languageMode: LanguageMode::English,
        );

        $this->actingAs($myanmarUser)
            ->get('/')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('uiLanguageMode', LanguageMode::Myanmar->value)
                    ->where('account.email', 'myanmar-user@example.test'),
            );

        $this->actingAs($englishUser)
            ->get('/')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('uiLanguageMode', LanguageMode::English->value)
                    ->where('account.email', 'english-user@example.test'),
            );
    }

    public function test_workspace_truth_and_current_business_are_identical_across_language_modes(): void
    {
        $business = $this->createBusiness(
            name: 'မြန်မာ & EN Workspace',
            baseCurrency: 'THB',
        );

        $hiddenBusiness = $this->createBusiness(
            name: 'Hidden Business',
            baseCurrency: 'MMK',
        );

        $workspace = [
            'businesses' => [
                [
                    'id' => $business->id,
                    'name' => 'မြန်မာ & EN Workspace',
                ],
            ],
            'currentBusiness' => [
                'id' => $business->id,
                'name' => 'မြန်မာ & EN Workspace',
            ],
        ];

        foreach ([
            LanguageMode::English,
            LanguageMode::Myanmar,
            LanguageMode::Mixed,
        ] as $index => $languageMode) {
            $user = $this->createActiveUser(
                email: sprintf('workspace-mode-%d@example.test', $index),
                languageMode: $languageMode,
            );

            $this->createActiveMembership($user, $business);

            $response = $this
                ->actingAs($user)
                ->withSession([
                    EnsureCurrentBusinessContext::SESSION_KEY => $business->id,
                ])
                ->get('/');

            $response
                ->assertOk()
                ->assertInertia(
                    fn (Assert $page) => $page
                        ->component('AccountHome')
                        ->where('uiLanguageMode', $languageMode->value)
                        ->where('workspace', $workspace),
                );

            $response->assertDontSee($hiddenBusiness->id);
            $response->assertDontSee('Hidden Business');
        }

        $business->refresh();
        $hiddenBusiness->refresh();

        $this->assertSame('မြန်မာ & EN Workspace', $business->name);
        $this->assertSame('THB', $business->base_currency);
        $this->assertSame('Hidden Business', $hiddenBusiness->name);
        $this->assertSame('MMK', $hiddenBusiness->base_currency);
        $this->assertSame(3, Membership::query()->count());
    }

    public function test_identity_values_remain_verbatim_across_language_modes(): void
    {
        foreach ([
            LanguageMode::English,
            LanguageMode::Myanmar,
            LanguageMode::Mixed,
        ] as $index => $languageMode) {
            $displayName = sprintf('Partner %d — မြန်မာ + EN', $index);
            $email = sprintf('identity-%d@example.test', $index);

            $user = $this->createActiveUser(
                email: $email,
                displayName: $displayName,
                languageMode: $languageMode,
                timezone: 'Asia/Bangkok',
            );

            $this->actingAs($user)
                ->get('/account/settings')
                ->assertOk()
                ->assertInertia(
                    fn (Assert $page) => $page
                        ->component('Account/Settings')
                        ->where('uiLanguageMode', $languageMode->value)
                        ->where('account.email', $email)
                        ->where('account.profile.display_name', $displayName)
                        ->where('account.profile.language_mode', $languageMode->value)
                        ->where('account.profile.timezone', 'Asia/Bangkok'),
                );
        }
    }

    public function test_translation_catalog_has_exact_key_parity_for_all_three_modes(): void
    {
        $source = file_get_contents(
            base_path('resources/js/i18n/catalog.ts'),
        );

        $this->assertIsString($source);

        $english = $this->extractCatalogKeys(
            $source,
            'const englishCatalog = {',
            '} as const;',
        );

        $myanmar = $this->extractCatalogKeys(
            $source,
            'const myanmarCatalog = {',
            '} satisfies TranslationCatalog;',
        );

        $mixed = $this->extractCatalogKeys(
            $source,
            'const mixedCatalog = {',
            '} satisfies TranslationCatalog;',
        );

        $this->assertNotEmpty($english);
        $this->assertSame($english, $myanmar);
        $this->assertSame($english, $mixed);

        $this->assertStringContainsString(
            'satisfies Record<UiLanguageMode, TranslationCatalog>',
            $source,
        );

        $this->assertStringContainsString(
            'satisfies Record<UiLanguageMode, TerminologyCatalog>',
            $source,
        );
    }

    public function test_localized_surfaces_use_central_i18n_without_language_specific_architecture(): void
    {
        $surfacePaths = [
            'resources/js/components/BusinessSwitcher.vue',
            'resources/js/components/WorkspaceNavigation.vue',
            'resources/js/layouts/AuthenticatedLayout.vue',
            'resources/js/pages/AccountHome.vue',
            'resources/js/pages/Account/Settings.vue',
            'resources/js/pages/Auth/Login.vue',
            'resources/js/pages/Businesses/Create.vue',
        ];

        $knownHardCodedCopy = [
            'Workspace navigation',
            'Open workspace navigation',
            'Close workspace navigation',
            'No Business selected',
            'No accessible Businesses',
            'Select a Business',
            'Business could not be selected.',
            'Signed-in identity',
            'Manage your account profile and personal preferences.',
            'Account settings updated.',
            'Access your private partnership business workspace.',
            'How is this Business entering PBR?',
            'Select the current stage independently from the Business origin.',
            'Enter a three-letter uppercase currency code, for example USD, MMK, or THB.',
        ];

        foreach ($surfacePaths as $path) {
            $source = file_get_contents(base_path($path));

            $this->assertIsString($source, $path);
            $this->assertStringContainsString('useI18n', $source, $path);
            $this->assertStringNotContainsString('uiLanguageMode', $source, $path);

            $branchingSource = str_replace(
                'form.errors.language_mode',
                'validation_language_mode_error',
                $source,
            );

            $this->assertDoesNotMatchRegularExpression(
                '/v-(?:if|show)="[^"]*(?:form\.language_mode|account\.[^"]*language_mode|profile\.[^"]*language_mode|props\.[^"]*language_mode)/',
                $branchingSource,
                $path,
            );

            foreach ($knownHardCodedCopy as $copy) {
                $this->assertStringNotContainsString(
                    $copy,
                    $source,
                    sprintf('%s contains hard-coded localized copy: %s', $path, $copy),
                );
            }
        }

        $layoutSource = file_get_contents(
            base_path('resources/js/layouts/AuthenticatedLayout.vue'),
        );

        $workspaceNavigationSource = file_get_contents(
            base_path('resources/js/components/WorkspaceNavigation.vue'),
        );

        $this->assertIsString($layoutSource);
        $this->assertIsString($workspaceNavigationSource);

        $this->assertStringContainsString(
            "t('shell.openNavigation')",
            $layoutSource,
        );

        $this->assertStringContainsString(
            "t('shell.closeNavigation')",
            $layoutSource,
        );

        $this->assertStringContainsString(
            "t('nav.workspaceNavigation')",
            $workspaceNavigationSource,
        );

        $this->assertStringContainsString(
            ':aria-current=',
            $workspaceNavigationSource,
        );

        $settingsSource = file_get_contents(
            base_path('resources/js/pages/Account/Settings.vue'),
        );

        $createBusinessSource = file_get_contents(
            base_path('resources/js/pages/Businesses/Create.vue'),
        );

        $this->assertIsString($settingsSource);
        $this->assertIsString($createBusinessSource);

        $this->assertStringContainsString(
            '{{ option }}',
            $settingsSource,
        );

        $this->assertStringContainsString(
            'v-model="form.display_name"',
            $settingsSource,
        );

        $this->assertStringContainsString(
            'v-model="form.name"',
            $createBusinessSource,
        );

        $this->assertStringContainsString(
            'v-model="form.base_currency"',
            $createBusinessSource,
        );

        $this->assertStringContainsString(
            'placeholder="USD"',
            $createBusinessSource,
        );

        $i18nSources = implode("\n", [
            (string) file_get_contents(
                base_path('resources/js/i18n/catalog.ts'),
            ),
            (string) file_get_contents(
                base_path('resources/js/i18n/useI18n.ts'),
            ),
        ]);

        foreach ([
            'localStorage',
            'sessionStorage',
            'navigator.language',
            'document.cookie',
        ] as $duplicateTruth) {
            $this->assertStringNotContainsString(
                $duplicateTruth,
                $i18nSources,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function extractCatalogKeys(
        string $source,
        string $startMarker,
        string $endMarker,
    ): array {
        $start = strpos($source, $startMarker);

        $this->assertNotFalse($start);

        $start += strlen($startMarker);

        $end = strpos($source, $endMarker, $start);

        $this->assertNotFalse($end);

        $body = substr($source, $start, $end - $start);

        preg_match_all(
            "/^\\s*'([^']+)':/m",
            $body,
            $matches,
        );

        /** @var list<string> $keys */
        $keys = $matches[1];

        sort($keys);

        return $keys;
    }

    private function createActiveUser(
        string $email,
        string $displayName = 'A11 User',
        LanguageMode $languageMode = LanguageMode::English,
        string $timezone = 'UTC',
    ): User {
        $user = User::query()->create([
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        UserProfile::query()->create([
            'user_id' => $user->id,
            'display_name' => $displayName,
            'language_mode' => $languageMode,
            'timezone' => $timezone,
        ]);

        return $user->refresh()->load('profile');
    }

    private function createBusiness(
        string $name,
        string $baseCurrency,
    ): Business {
        return Business::query()->create([
            'name' => $name,
            'origin_type' => BusinessOriginType::StartedThroughPbr,
            'business_stage' => BusinessStage::Planning,
            'setup_phase' => SetupPhase::Formation,
            'workspace_status' => WorkspaceStatus::Active,
            'base_currency' => $baseCurrency,
        ]);
    }

    private function createActiveMembership(
        User $user,
        Business $business,
    ): Membership {
        return Membership::query()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'access_status' => MembershipAccessStatus::Active,
        ]);
    }
}
