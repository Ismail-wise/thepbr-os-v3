import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

const OWNER_EMAIL = 'f6e-owner@example.com';
const VIEWER_EMAIL = 'f6e-viewer@example.com';
const BUSINESS = 'F6E Conflict Business';

const password = process.env.F6E_E2E_PASSWORD;

if (!password) {
    throw new Error(
        'F6E_E2E_PASSWORD must be provided through the environment.',
    );
}

const resetFixture = () => {
    if (process.env.APP_ENV !== 'testing') {
        throw new Error('F6E E2E fixture reset is allowed only in APP_ENV=testing.');
    }

    execFileSync('php', ['tests/E2E/support/prepare-f6e-e2e.php'], {
        cwd: process.cwd(),
        env: {
            ...process.env,
            F6E_E2E_RESET: '1',
        },
        stdio: 'pipe',
    });
};

const signIn = async (page: Page, email: string) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
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

const openConflict = async (page: Page) => {
    await page
        .getByRole('navigation', {
            name: 'Workspace navigation',
        })
        .getByRole('link', {
            name: 'Conflict Resolution',
            exact: true,
        })
        .click();

    await page.waitForURL(
        (url) => url.pathname === '/conflict',
        { waitUntil: 'domcontentloaded' },
    );

    await expect(
        page.getByRole('heading', {
            name: 'Conflict Resolution',
            exact: true,
        }),
    ).toBeVisible();
};

test(
    'F6E Conflict command center preserves restricted existence and governed resolution boundaries',
    async ({ page }, testInfo) => {
        test.skip(
            testInfo.project.name !== 'chromium-desktop',
            'F6E deterministic journey runs only in desktop Chromium.',
        );

        resetFixture();

        await signIn(page, OWNER_EMAIL);
        await switchBusiness(page);
        await openConflict(page);

        await expect(
            page.getByText(
                'Case participation is not access or Governance authority. Mediation is not Approval, and a signed settlement is not Effective until the governed record lifecycle is complete.',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            page.getByText('Current Effective Conflict Procedure', {
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page.getByText('v1 · Effective', { exact: true }),
        ).toBeVisible();

        await expect(
            page.getByTestId('conflict-visible-count'),
        ).toHaveText('1');

        const preparedRow = page
            .getByTestId('conflict-case-row')
            .filter({ hasText: 'relationship breakdown' });

        await expect(preparedRow).toBeVisible();

        await preparedRow
            .getByRole('link', {
                name: 'Open case workspace',
                exact: true,
            })
            .click();

        await expect(
            page.getByTestId('conflict-case-workspace'),
        ).toBeVisible();
        await expect(
            page.getByText(
                'Prepared restricted conflict for deterministic browser verification.',
                { exact: true },
            ),
        ).toBeVisible();
        await expect(
            page.getByTestId('conflict-mediation-count'),
        ).toHaveText('1');
        await expect(
            page.getByTestId('conflict-settlement-count'),
        ).toHaveText('1');
        await expect(
            page.getByTestId('conflict-action-count'),
        ).toHaveText('1');

        await page.goto('/conflict');
        await expect(
            page.getByTestId('conflict-visible-count'),
        ).toHaveText('1');

        const createCase = page
            .locator('details')
            .filter({ hasText: 'Open Conflict Case' });

        await createCase.locator('summary').click();
        await createCase
            .getByLabel('Description', { exact: true })
            .fill('Browser-created restricted conflict');
        await createCase
            .getByLabel('Business impact', { exact: true })
            .fill('Browser journey requires structured resolution.');

        await createCase
            .getByRole('button', {
                name: 'Open Conflict Case',
                exact: true,
            })
            .click();

        await expect(
            page.getByTestId('conflict-visible-count'),
        ).toHaveText('2');

        const browserRow = page
            .getByTestId('conflict-case-row')
            .filter({ hasText: 'ordinary disagreement' });

        await expect(browserRow).toBeVisible();

        const restrictedHref = await browserRow
            .getByRole('link', {
                name: 'Open case workspace',
                exact: true,
            })
            .getAttribute('href');

        expect(restrictedHref).toBeTruthy();

        await browserRow
            .getByRole('link', {
                name: 'Open case workspace',
                exact: true,
            })
            .click();

        await expect(
            page.getByText('Browser-created restricted conflict', {
                exact: true,
            }),
        ).toBeVisible();

        await page
            .getByRole('button', {
                name: 'Start Direct Discussion',
                exact: true,
            })
            .click();

        await expect(
            page.getByText('direct discussion', { exact: true }).first(),
        ).toBeVisible();

        await page
            .getByLabel('Issues discussed', { exact: true })
            .fill('Browser discussion captured the exact issues.');
        await page
            .getByLabel('Party positions', { exact: true })
            .fill('Browser discussion preserved the party position.');
        await page
            .getByLabel('Proposed solutions', { exact: true })
            .fill('Proceed to neutral mediation.');

        await page
            .getByRole('button', {
                name: 'Record Discussion',
                exact: true,
            })
            .click();

        await expect(
            page.getByText('mediation', { exact: true }).first(),
        ).toBeVisible();

        await page
            .getByPlaceholder('External neutral mediator reference')
            .fill('Browser Neutral Mediator');
        await page
            .getByPlaceholder('Neutrality / conflict check')
            .fill('No identified conflict with the recorded party.');

        const mediationAt = new Date(Date.now() + 86_400_000)
            .toISOString()
            .slice(0, 16);

        await page
            .locator('input[type="datetime-local"]')
            .first()
            .fill(mediationAt);

        await page
            .getByRole('button', {
                name: 'Schedule Mediation',
                exact: true,
            })
            .click();

        await expect(
            page.getByTestId('conflict-mediation-count'),
        ).toHaveText('1');
        await expect(
            page.getByText('mediation', { exact: true }).first(),
        ).toBeVisible();

        await page
            .getByRole('navigation', {
                name: 'Workspace navigation',
            })
            .getByRole('link', {
                name: 'Home',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/$/);
        await expect(
            page.getByRole('heading', {
                name: 'Account',
                exact: true,
            }),
        ).toBeVisible();

        await page
            .getByRole('button', {
                name: 'Sign out',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/login$/);

        await signIn(page, VIEWER_EMAIL);
        await switchBusiness(page);
        await openConflict(page);

        await expect(
            page.getByTestId('conflict-visible-count'),
        ).toHaveText('0');
        await expect(
            page.getByTestId('conflict-attention-count'),
        ).toHaveText('0');
        await expect(
            page.getByTestId('conflict-case-row'),
        ).toHaveCount(0);
        await expect(
            page.getByText('Browser-created restricted conflict', {
                exact: true,
            }),
        ).toHaveCount(0);
        await expect(
            page.getByTestId('conflict-open-case'),
        ).toHaveCount(0);

        await page.goto(restrictedHref as string);

        await expect(
            page.getByTestId('conflict-visible-count'),
        ).toHaveText('0');
        await expect(
            page.getByTestId('conflict-case-workspace'),
        ).toHaveCount(0);
        await expect(
            page.getByText('Browser-created restricted conflict', {
                exact: true,
            }),
        ).toHaveCount(0);
    },
);
