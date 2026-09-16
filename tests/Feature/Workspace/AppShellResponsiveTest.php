<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use Tests\TestCase;

final class AppShellResponsiveTest extends TestCase
{
    public function test_authenticated_layout_reuses_shared_workspace_components_across_breakpoints(): void
    {
        $layout = $this->source(
            'resources/js/layouts/AuthenticatedLayout.vue',
        );

        $this->assertSame(
            2,
            substr_count($layout, '<WorkspaceNavigation'),
        );

        $this->assertSame(
            2,
            substr_count($layout, '<BusinessSwitcher'),
        );

        $this->assertStringContainsString(
            'class="hidden bg-white lg:sticky',
            $layout,
        );

        $this->assertStringContainsString(
            'class="flex min-w-0 items-center gap-3 lg:hidden"',
            $layout,
        );

        $this->assertStringContainsString(
            'select-id="business-switcher-desktop"',
            $layout,
        );

        $this->assertStringContainsString(
            'select-id="business-switcher-mobile"',
            $layout,
        );
    }

    public function test_mobile_navigation_uses_native_dialog_accessibility_and_focus_contract(): void
    {
        $layout = $this->source(
            'resources/js/layouts/AuthenticatedLayout.vue',
        );

        $this->assertStringContainsString('<dialog', $layout);

        $this->assertStringContainsString(
            'id="mobile-workspace-navigation"',
            $layout,
        );

        $this->assertStringContainsString(
            'aria-controls="mobile-workspace-navigation"',
            $layout,
        );

        $this->assertStringContainsString(
            ':aria-expanded="mobileNavigationOpen ? \'true\' : \'false\'"',
            $layout,
        );

        $this->assertStringContainsString(
            ':aria-label="t(\'shell.openNavigation\')"',
            $layout,
        );

        $this->assertStringContainsString(
            ':aria-label="t(\'shell.closeNavigation\')"',
            $layout,
        );

        $this->assertStringContainsString(
            'dialog.showModal();',
            $layout,
        );

        $this->assertStringContainsString(
            'dialog.close();',
            $layout,
        );

        $this->assertStringContainsString(
            '@cancel="handleMobileNavigationCancel"',
            $layout,
        );

        $this->assertStringContainsString(
            '@close="handleMobileNavigationClose"',
            $layout,
        );

        $this->assertStringContainsString(
            'mobileNavigationTrigger.value?.focus()',
            $layout,
        );

        $this->assertStringContainsString(
            "window.matchMedia('(min-width: 1024px)')",
            $layout,
        );
    }

    public function test_workspace_navigation_exposes_current_page_and_keyboard_contract(): void
    {
        $navigation = $this->source(
            'resources/js/components/WorkspaceNavigation.vue',
        );

        $this->assertStringContainsString(
            ':aria-current="isCurrent(\'/\') ? \'page\' : undefined"',
            $navigation,
        );

        $this->assertStringContainsString(
            ':aria-current="isCurrent(\'/businesses/create\') ? \'page\' : undefined"',
            $navigation,
        );

        $this->assertStringContainsString(
            ':aria-current="isCurrent(\'/account/settings\') ? \'page\' : undefined"',
            $navigation,
        );

        $this->assertStringContainsString(
            'focus-visible:outline-none',
            $navigation,
        );

        $this->assertStringContainsString(
            'focus-visible:ring-2',
            $navigation,
        );

        $this->assertStringContainsString(
            'min-h-11',
            $navigation,
        );

        $this->assertStringContainsString(
            "@click=\"emit('navigate')\"",
            $navigation,
        );
    }

    public function test_shell_keeps_current_business_visible_overflow_safe_and_motion_free(): void
    {
        $layout = $this->source(
            'resources/js/layouts/AuthenticatedLayout.vue',
        );

        $navigation = $this->source(
            'resources/js/components/WorkspaceNavigation.vue',
        );

        $switcher = $this->source(
            'resources/js/components/BusinessSwitcher.vue',
        );

        $this->assertSame(
            1,
            substr_count(
                $layout,
                "{{ t('shell.currentBusiness') }}",
            ),
        );

        $this->assertStringContainsString(
            'class="mt-1 truncate text-sm font-semibold text-slate-950"',
            $layout,
        );

        $this->assertStringContainsString(
            ':title="currentBusinessName"',
            $layout,
        );

        $this->assertStringContainsString(
            'min-h-11',
            $layout,
        );

        $this->assertDoesNotMatchRegularExpression(
            '/(?:transition-|animate-|duration-|motion-)/',
            $layout.$navigation.$switcher,
        );
    }

    public function test_business_switcher_supports_multiple_shell_instances_without_changing_authorized_switch_path(): void
    {
        $switcher = $this->source(
            'resources/js/components/BusinessSwitcher.vue',
        );

        $this->assertStringContainsString(
            'selectId?: string;',
            $switcher,
        );

        $this->assertStringContainsString(
            "props.selectId ?? 'business-switcher'",
            $switcher,
        );

        $this->assertStringContainsString(
            ':for="resolvedSelectId"',
            $switcher,
        );

        $this->assertStringContainsString(
            ':id="resolvedSelectId"',
            $switcher,
        );

        $this->assertStringContainsString(
            "form.post('/current-business'",
            $switcher,
        );
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
