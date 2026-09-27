import { expect, test, type Page } from '@playwright/test';

const E2E_EMAIL = 'f6c-browser@example.com';
const BUSINESS = 'F6C Finance Rewards Business';

const password = process.env.F6C_E2E_PASSWORD;

if (!password) {
    throw new Error(
        'F6C_E2E_PASSWORD must be provided through the environment.',
    );
}

const signIn = async (page: Page) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(E2E_EMAIL);
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

test(
    'F6C Finance and Rewards command centers preserve authority and scenario boundaries',
    async ({ page }, testInfo) => {
        test.skip(
            testInfo.project.name !== 'chromium-desktop',
            'F6C deterministic journey runs only in desktop Chromium.',
        );

        await signIn(page);
        await switchBusiness(page);

        const navigation = page.getByRole('navigation', {
            name: 'Workspace navigation',
        });

        await navigation
            .getByRole('link', {
                name: 'Finance & Control',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/finance$/);

        await expect(
            page.getByRole('heading', {
                name: 'Finance & Control',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page.getByText(
                'System permission never creates Finance or Governance authority. Requester, Governance approval and Payer remain separately controlled.',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            page.getByText('No Effective Finance Policy yet.', {
                exact: true,
            }),
        ).toBeVisible();

        const financePolicy = page
            .locator('details')
            .filter({
                has: page.getByText(
                    'Create Finance Policy Draft / Amendment',
                    { exact: true },
                ),
            });

        await financePolicy.locator('summary').click();

        await expect(
            financePolicy.getByText(
                'Never enter password, PIN, OTP, token or secret credentials.',
                { exact: true },
            ),
        ).toBeVisible();

        await navigation
            .getByRole('link', {
                name: 'Rewards & Distribution',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/rewards$/);

        await expect(
            page.getByRole('heading', {
                name: 'Rewards & Distribution',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page.getByText(
                'Ownership does not determine salary. A calculated Distribution is not payable until Finance verification, Governance approval and payment evidence are complete.',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            page.getByText(
                'Pure calculation only. This never changes live Finance or Ownership truth.',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            page.getByRole('button', {
                name: /Apply Scenario/i,
            }),
        ).toHaveCount(0);

        await expect(
            page.getByText('No Effective Reward Policy yet.', {
                exact: false,
            }),
        ).toBeVisible();
    },
);
