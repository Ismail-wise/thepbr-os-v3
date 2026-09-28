import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

const OWNER_EMAIL = 'f7-owner@example.com';
const BUSINESS = 'F7 Lifecycle Intelligence Business';

const password = process.env.F7_E2E_PASSWORD;

if (!password) {
    throw new Error(
        'F7_E2E_PASSWORD must be provided through the environment.',
    );
}

const resetFixture = () => {
    if (process.env.APP_ENV !== 'testing') {
        throw new Error(
            'F7 E2E fixture reset is allowed only in APP_ENV=testing.',
        );
    }

    execFileSync('php', ['tests/E2E/support/prepare-f7-e2e.php'], {
        cwd: process.cwd(),
        env: {
            ...process.env,
            F7_E2E_RESET: '1',
        },
        stdio: 'pipe',
    });
};

const signIn = async (page: Page) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(OWNER_EMAIL);
    await page.getByLabel('Password', { exact: true }).fill(password);

    const homeNavigation = page.waitForURL(
        (url) => url.pathname === '/',
        {
            waitUntil: 'domcontentloaded',
            timeout: 10_000,
        },
    );

    await page
        .getByRole('button', {
            name: 'Sign in',
            exact: true,
        })
        .click();

    await homeNavigation;
};

const switchBusiness = async (page: Page) => {
    const switcher = page
        .locator('aside')
        .getByRole('combobox', {
            name: 'Select current Business',
            exact: true,
        });

    await switcher.selectOption({ label: BUSINESS });

    await expect(
        page
            .locator('header')
            .first()
            .getByText(BUSINESS, { exact: true }),
    ).toBeVisible();
};

const openNav = async (
    page: Page,
    linkName: string,
    pathname: string,
    heading: string,
) => {
    await page
        .getByRole('navigation', {
            name: 'Workspace navigation',
        })
        .getByRole('link', {
            name: linkName,
            exact: true,
        })
        .click();

    await page.waitForURL(
        (url) => url.pathname === pathname,
        { waitUntil: 'domcontentloaded' },
    );

    await expect(
        page.getByRole('heading', {
            name: heading,
            exact: true,
        }),
    ).toBeVisible();
};

test(
    'F7 lifecycle intelligence command center remains business scoped and advisory',
    async ({ page }, testInfo) => {
        test.skip(
            testInfo.project.name !== 'chromium-desktop',
            'F7 deterministic journey runs only in desktop Chromium.',
        );

        resetFixture();

        await signIn(page);
        await switchBusiness(page);

        await openNav(
            page,
            'Partner Changes',
            '/changes/partner-changes',
            'Partner Changes',
        );

        await openNav(
            page,
            'Exit & Buyout',
            '/changes/exit',
            'Exit & Buyout',
        );

        await openNav(
            page,
            'Closure & Dissolution',
            '/changes/closure',
            'Closure & Dissolution',
        );

        await openNav(
            page,
            'Search',
            '/search',
            'Global Search',
        );

        await page
            .getByRole('search')
            .getByRole('searchbox')
            .fill('Lifecycle');

        await page
            .getByRole('search')
            .getByRole('button', {
                name: 'Search',
                exact: true,
            })
            .click();

        await expect(
            page.getByText('Lifecycle Local Partner', {
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page.getByText('Lifecycle Foreign Secret Partner', {
                exact: true,
            }),
        ).toHaveCount(0);

        await expect(
            page.getByText('1 authorized matches', {
                exact: true,
            }),
        ).toBeVisible();

        await openNav(
            page,
            'Business Health',
            '/health',
            'Business Health',
        );

        await openNav(
            page,
            'Reports',
            '/reports',
            'Reports & Business Pack',
        );

        await openNav(
            page,
            'Import',
            '/import',
            'Import & Reconciliation',
        );

        await openNav(
            page,
            'Archive & Portability',
            '/records/portability',
            'Archive & Portability',
        );

        await expect(
            page.getByText(
                'Business Export is a generated representation only. Canonical truth remains in structured records.',
                { exact: true },
            ),
        ).toBeVisible();

        await openNav(
            page,
            'PBR AI',
            '/ai',
            'PBR AI',
        );

        await expect(
            page.getByText(
                'PBR AI is not configured in this environment.',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            page.getByText(
                'Advisory only. PBR AI cannot approve, vote, sign, change Ownership or Governance authority, issue payment, revoke rights, archive or close a Business, or create Effective truth.',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            page.getByRole('button', {
                name: 'Ask',
                exact: true,
            }),
        ).toBeDisabled();
    },
);
