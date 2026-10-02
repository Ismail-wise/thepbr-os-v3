<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use Tests\TestCase;

final class AppShellResponsiveTest extends TestCase
{
    public function test_authenticated_layout_delegates_to_reusable_pbr_app_shell(): void
    {
        $layout = $this->source(
            'resources/js/layouts/AuthenticatedLayout.vue',
        );

        $shell = $this->source(
            'resources/js/components/shell/PbrAppShell.vue',
        );

        $this->assertStringContainsString(
            '<PbrAppShell>',
            $layout,
        );

        foreach ([
            '<BusinessSidebar',
            '<TopCommandBar',
            '<MobileNavigation',
        ] as $component) {
            $this->assertStringContainsString($component, $shell);
        }
    }

    public function test_shell_keeps_current_business_visible_without_exposing_technical_identifiers(): void
    {
        $shellSources = implode("\n", [
            $this->source('resources/js/components/shell/PbrAppShell.vue'),
            $this->source('resources/js/components/shell/BusinessSidebar.vue'),
            $this->source('resources/js/components/shell/TopCommandBar.vue'),
            $this->source('resources/js/components/shell/BreadcrumbContext.vue'),
        ]);

        $this->assertStringContainsString(
            "t('shell.noBusinessSelected')",
            $shellSources,
        );

        $this->assertStringContainsString(
            ':current-business="workspace?.currentBusiness ?? null"',
            $shellSources,
        );

        $this->assertDoesNotMatchRegularExpression(
            '/(?:formal_record_version|proposal_version|authority_snapshot|content_hash|snapshot_hash|sha-?256)/i',
            $shellSources,
        );
    }

    public function test_mobile_navigation_preserves_native_dialog_focus_and_breakpoint_contract(): void
    {
        $topBar = $this->source(
            'resources/js/components/shell/TopCommandBar.vue',
        );

        $mobile = $this->source(
            'resources/js/components/shell/MobileNavigation.vue',
        );

        $this->assertStringContainsString(
            'aria-controls="mobile-workspace-navigation"',
            $topBar,
        );

        $this->assertStringContainsString(
            ':aria-expanded="mobileNavigationOpen ? \'true\' : \'false\'"',
            $topBar,
        );

        foreach ([
            '<dialog',
            'id="mobile-workspace-navigation"',
            'dialog.value.showModal();',
            'dialog.value.close();',
            '@cancel="handleCancel"',
            '@close="handleClose"',
            "window.matchMedia('(min-width: 1024px)')",
            'restoreTarget?.focus()',
        ] as $contract) {
            $this->assertStringContainsString($contract, $mobile);
        }
    }

    public function test_workspace_navigation_is_grouped_keyboard_visible_and_touch_sized(): void
    {
        $navigation = $this->source(
            'resources/js/components/WorkspaceNavigation.vue',
        );

        foreach ([
            "'nav.group.workspace'",
            "'nav.group.setup'",
            "'nav.group.operate'",
            "'nav.group.protect'",
            "'nav.group.changes'",
            "'nav.group.records'",
            "'nav.group.account'",
        ] as $group) {
            $this->assertStringContainsString($group, $navigation);
        }

        $this->assertStringContainsString(
            ':aria-current="isCurrent(item.href) ? \'page\' : undefined"',
            $navigation,
        );

        $this->assertStringContainsString('pbr-touch', $navigation);
        $this->assertStringContainsString(
            '@click="emit(\'navigate\')"',
            $navigation,
        );
    }

    public function test_business_and_language_switchers_preserve_authorized_server_paths(): void
    {
        $business = $this->source(
            'resources/js/components/BusinessSwitcher.vue',
        );

        $language = $this->source(
            'resources/js/components/shell/LanguageSwitcher.vue',
        );

        $this->assertStringContainsString(
            "form.post('/current-business'",
            $business,
        );

        $this->assertStringContainsString(
            'select-id="business-switcher-desktop"',
            $this->source(
                'resources/js/components/shell/BusinessSidebar.vue',
            ),
        );

        $this->assertStringContainsString(
            'select-id="business-switcher-mobile"',
            $this->source(
                'resources/js/components/shell/MobileNavigation.vue',
            ),
        );

        $this->assertStringContainsString(
            "form.patch('/account/language'",
            $language,
        );

        $this->assertStringNotContainsString('localStorage', $language);
        $this->assertStringNotContainsString('sessionStorage', $language);
    }

    public function test_premium_tokens_primitives_and_accessibility_foundation_are_shared(): void
    {
        $css = $this->source('resources/css/app.css');

        foreach ([
            '--pbr-canvas: #f2f5f2',
            '--pbr-green: #0d6a3b',
            '--pbr-ink: #10231a',
            '.pbr-touch',
            'min-height: 44px',
            ':focus-visible',
            'prefers-reduced-motion',
        ] as $contract) {
            $this->assertStringContainsString($contract, $css);
        }

        foreach ([
            'PbrButton.vue',
            'PbrCard.vue',
            'PbrBadge.vue',
            'PbrField.vue',
        ] as $component) {
            $this->assertFileExists(
                base_path('resources/js/components/ui/'.$component),
            );
        }
    }

    private function source(string $path): string
    {
        $source = file_get_contents(base_path($path));

        $this->assertIsString(
            $source,
            sprintf('Expected source file to be readable: %s', $path),
        );

        return $source;
    }
}
