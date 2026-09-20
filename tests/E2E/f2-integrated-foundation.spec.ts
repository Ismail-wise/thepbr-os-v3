import { expect, test, type Page } from '@playwright/test';

const E2E_EMAIL = 'f2-round5-browser@example.com';
const BUSINESS = 'F2 Integrated Business';
const PROFILE = 'F2 Operator';

const password = process.env.F2_E2E_PASSWORD;

if (!password) {
    throw new Error(
        'F2_E2E_PASSWORD must be provided through the environment.',
    );
}

const signIn = async (page: Page) => {
    await page.goto('/login');

    await page.getByLabel('Email', { exact: true }).fill(E2E_EMAIL);
    await page.getByLabel('Password', { exact: true }).fill(password);

    await page
        .getByRole('button', { name: 'Sign in', exact: true })
        .click();

    await expect(page).toHaveURL(/\/$/);
};

const selectBusiness = async (page: Page) => {
    const switcher = page
        .locator('aside')
        .getByRole('combobox', {
            name: 'Select current Business',
            exact: true,
        });

    await switcher.selectOption({ label: BUSINESS });

    await expect(
        page.locator('header').first().getByText(BUSINESS, {
            exact: true,
        }),
    ).toBeVisible();
};

test(
    'F2 integrated workspace surfaces preserve access boundaries and all language modes',
    async ({ page }, testInfo) => {
        test.skip(
            testInfo.project.name !== 'chromium-desktop',
            'F2 integrated journey runs only in desktop Chromium.',
        );

        await signIn(page);
        await selectBusiness(page);

        let navigation = page.getByRole('navigation', {
            name: 'Workspace navigation',
        });

        await navigation
            .getByRole('link', {
                name: 'Workspace Access',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/workspace\/access$/);

        await expect(
            page.getByRole('heading', {
                name: 'Workspace Access',
                exact: true,
            }),
        ).toBeVisible();

        await expect(page.getByText(PROFILE, { exact: true })).toBeVisible();

        await expect(
            page.getByText('records.activity.view', { exact: true }),
        ).toBeVisible();

        await expect(
            page.getByText(
                'System access is separate from ownership and governance authority.',
                { exact: true },
            ),
        ).toBeVisible();

        navigation = page.getByRole('navigation', {
            name: 'Workspace navigation',
        });

        await navigation
            .getByRole('link', {
                name: 'Document Vault',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/records\/documents$/);

        await expect(
            page.getByRole('heading', {
                name: 'Document Vault',
                exact: true,
            }),
        ).toBeVisible();

        navigation = page.getByRole('navigation', {
            name: 'Workspace navigation',
        });

        await navigation
            .getByRole('link', {
                name: 'Activity',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/records\/activity$/);

        await expect(
            page.getByRole('heading', {
                name: 'Activity',
                exact: true,
            }),
        ).toBeVisible();

        navigation = page.getByRole('navigation', {
            name: 'Workspace navigation',
        });

        await navigation
            .getByRole('link', {
                name: 'Profile & Settings',
                exact: true,
            })
            .click();

        const languageSelect = page.locator(
            'select[name="language_mode"]',
        );

        await languageSelect.selectOption('my');

        await page
            .getByRole('button', {
                name: 'Save settings',
                exact: true,
            })
            .click();

        await expect(languageSelect).toHaveValue('my');

        navigation = page.getByRole('navigation', {
            name: 'လုပ်ငန်းအလုပ်ခွင် လမ်းညွှန်',
        });

        await navigation
            .getByRole('link', {
                name: 'လုပ်ငန်းအသုံးပြုခွင့်',
                exact: true,
            })
            .click();

        await expect(
            page.getByRole('heading', {
                name: 'လုပ်ငန်းအသုံးပြုခွင့်',
                exact: true,
            }),
        ).toBeVisible();

        navigation = page.getByRole('navigation', {
            name: 'လုပ်ငန်းအလုပ်ခွင် လမ်းညွှန်',
        });

        await navigation
            .getByRole('link', {
                name: 'ကိုယ်ရေးအချက်အလက်နှင့် ဆက်တင်များ',
                exact: true,
            })
            .click();

        await languageSelect.selectOption('mixed');

        await page
            .getByRole('button', {
                name: 'ဆက်တင်များ သိမ်းမည်',
                exact: true,
            })
            .click();

        await expect(languageSelect).toHaveValue('mixed');

        navigation = page.getByRole('navigation', {
            name: 'Business Workspace လမ်းညွှန်',
        });

        await navigation
            .getByRole('link', {
                name: 'Workspace Access · လုပ်ငန်းအသုံးပြုခွင့်',
                exact: true,
            })
            .click();

        await expect(
            page.getByRole('heading', {
                name: 'Workspace Access · လုပ်ငန်းအသုံးပြုခွင့်',
                exact: true,
            }),
        ).toBeVisible();
    },
);
