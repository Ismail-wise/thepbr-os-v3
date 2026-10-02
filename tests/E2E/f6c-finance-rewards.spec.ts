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
    const switcher = page.locator('#business-switcher-desktop');

    await switcher.selectOption({ label: BUSINESS });

    await expect(
        page
            .locator('header')
            .first()
            .getByText(BUSINESS, { exact: true }),
    ).toBeVisible();
};

const setLanguageMode = async (
    page: Page,
    mode: 'en' | 'my' | 'mixed',
) => {
    await page.goto('/account/settings');

    const settingsForm = page
        .locator('form')
        .filter({ has: page.locator('#language_mode') });

    await settingsForm
        .locator('#language_mode')
        .selectOption(mode);

    await settingsForm
        .locator('button[type="submit"]')
        .click();

    await expect(
        settingsForm.locator('#language_mode'),
    ).toHaveValue(mode);

    await page.goto('/rewards');
    await expect(page).toHaveURL(/\/rewards$/);
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
            page.getByText('Controlled money flow', { exact: true }),
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
            page.getByText('Compensation is not Ownership', {
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

        const rewardPolicy = page
            .locator('details')
            .filter({
                has: page.getByText(
                    'Create Reward Policy Draft / Amendment',
                    { exact: true },
                ),
            });

        await rewardPolicy.locator('summary').click();

        await expect(
            rewardPolicy.getByLabel(
                'Target cash buffer (minor units)',
                { exact: true },
            ),
        ).toBeVisible();

        const simulatorLabels = [
            'Approved net profit (minor units)',
            'Tax due (minor units)',
            'Debt due (minor units)',
            'Required reserve (minor units)',
            'Reinvestment (minor units)',
            'Adjustments (minor units)',
        ];

        for (const label of simulatorLabels) {
            await expect(
                page.getByText(label, { exact: true }),
            ).toBeVisible();
        }

        await setLanguageMode(page, 'my');
        await rewardPolicy.locator('summary').click();

        await expect(
            rewardPolicy.getByLabel(
                'ရည်မှန်းထားသော Cash buffer (minor units)',
                { exact: true },
            ),
        ).toBeVisible();

        for (const label of [
            'အတည်ပြုပြီး အသားတင်အမြတ် (minor units)',
            'ပေးရန်အခွန် (minor units)',
            'ပေးရန်အကြွေး (minor units)',
            'လိုအပ်သော reserve (minor units)',
            'ပြန်လည်ရင်းနှီးမြှုပ်နှံမှု (minor units)',
            'ချိန်ညှိမှုများ (minor units)',
        ]) {
            await expect(
                page.getByText(label, { exact: true }),
            ).toBeVisible();
        }

        await setLanguageMode(page, 'mixed');
        await rewardPolicy.locator('summary').click();

        await expect(
            rewardPolicy.getByLabel(
                'Target cash buffer · ရည်မှန်းထားသော Cash buffer (minor units)',
                { exact: true },
            ),
        ).toBeVisible();

        for (const label of [
            'Approved net profit · အသားတင်အမြတ် (minor units)',
            'Tax due · ပေးရန်အခွန် (minor units)',
            'Debt due · ပေးရန်အကြွေး (minor units)',
            'Required reserve · လိုအပ်သော reserve (minor units)',
            'Reinvestment · ပြန်လည်ရင်းနှီးမြှုပ်နှံမှု (minor units)',
            'Adjustments · ချိန်ညှိမှုများ (minor units)',
        ]) {
            await expect(
                page.getByText(label, { exact: true }),
            ).toBeVisible();
        }

        await setLanguageMode(page, 'en');
    },
);
