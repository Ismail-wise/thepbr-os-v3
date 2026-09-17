import { expect, test, type Page } from '@playwright/test';

const E2E_EMAIL = 'f1-a13-browser@example.com';
const e2ePassword = process.env.F1_E2E_PASSWORD;

if (!e2ePassword) {
    throw new Error(
        'F1_E2E_PASSWORD must be provided through the environment.',
    );
}

const E2E_PASSWORD: string = e2ePassword;

const BUSINESS_A = 'F1 A13 Started Business';
const BUSINESS_B = 'F1 A13 Existing Business';

const desktopBusinessSwitcher = (
    page: Page,
    accessibleName = 'Select current Business',
) =>
    page
        .locator('aside')
        .getByRole('combobox', { name: accessibleName, exact: true });

const shellHeader = (page: Page) => page.locator('header').first();

const expectBusinessOptions = async (
    page: Page,
    accessibleName: string,
) => {
    const switcher = desktopBusinessSwitcher(page, accessibleName);

    await expect(
        switcher.getByRole('option', {
            name: BUSINESS_A,
            exact: true,
        }),
    ).toHaveCount(1);

    await expect(
        switcher.getByRole('option', {
            name: BUSINESS_B,
            exact: true,
        }),
    ).toHaveCount(1);

    return switcher;
};

const expectCurrentBusiness = async (page: Page, name: string) => {
    await expect(
        shellHeader(page).getByText(name, { exact: true }),
    ).toBeVisible();
};

const signIn = async (page: Page) => {
    await page.goto('/login');

    await expect(
        page.getByRole('heading', { name: 'Sign in', exact: true }),
    ).toBeVisible();

    await page.getByLabel('Email', { exact: true }).fill(E2E_EMAIL);
    await page.getByLabel('Password', { exact: true }).fill(E2E_PASSWORD);

    await page
        .getByRole('button', { name: 'Sign in', exact: true })
        .click();

    await expect(page).toHaveURL(/\/$/);

    await expect(
        page.getByRole('heading', { name: 'Account', exact: true }),
    ).toBeVisible();
};

const createBusiness = async (
    page: Page,
    {
        name,
        origin,
        stage,
        currency,
    }: {
        name: string;
        origin: 'Started through PBR' | 'Existing Business imported into PBR';
        stage: 'Planning' | 'Validation';
        currency: 'THB' | 'MMK';
    },
) => {
    await page.getByLabel('Business name', { exact: true }).fill(name);

    const originRadio = page.getByRole('radio', {
        name: origin,
        exact: true,
    });

    await originRadio.check();
    await expect(originRadio).toBeChecked();

    await page
        .getByLabel('Current Business stage', { exact: true })
        .selectOption({ label: stage });

    await page
        .getByLabel('Base currency', { exact: true })
        .fill(currency);

    await page
        .getByRole('button', { name: 'Create Business', exact: true })
        .click();

    await expect(page).toHaveURL(/\/businesses\/create$/);

    await expect(page.getByRole('status')).toHaveText(
        'Business created successfully.',
    );

    await expectCurrentBusiness(page, name);
};

test(
    'desktop Boss journey: login, create both Business origins, switch current Business, and logout',
    async ({ page }, testInfo) => {
        test.skip(
            testInfo.project.name !== 'chromium-desktop',
            'Desktop Boss journey runs only in the desktop Chromium project.',
        );

        await signIn(page);

        await expect(
            page.getByRole('navigation', {
                name: 'Workspace navigation',
            }),
        ).toBeVisible();

        await expect(
            page.getByRole('button', {
                name: 'Open workspace navigation',
            }),
        ).toBeHidden();

        await expect(
            shellHeader(page).getByText('No Business selected', {
                exact: true,
            }),
        ).toBeVisible();

        const switcher = desktopBusinessSwitcher(page);

        await expect(switcher).toBeDisabled();

        await page
            .getByRole('navigation', {
                name: 'Workspace navigation',
            })
            .getByRole('link', {
                name: 'Create Business',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/businesses\/create$/);

        await expect(
            page.getByRole('heading', {
                name: 'Create Business',
                exact: true,
            }),
        ).toBeVisible();

        await createBusiness(page, {
            name: BUSINESS_A,
            origin: 'Started through PBR',
            stage: 'Planning',
            currency: 'THB',
        });

        await expect(switcher).toBeEnabled();

        await expect(
            switcher.getByRole('option', {
                name: BUSINESS_A,
                exact: true,
            }),
        ).toHaveCount(1);

        await createBusiness(page, {
            name: BUSINESS_B,
            origin: 'Existing Business imported into PBR',
            stage: 'Validation',
            currency: 'MMK',
        });

        await expect(
            switcher.getByRole('option', {
                name: BUSINESS_A,
                exact: true,
            }),
        ).toHaveCount(1);

        await expect(
            switcher.getByRole('option', {
                name: BUSINESS_B,
                exact: true,
            }),
        ).toHaveCount(1);

        await expectCurrentBusiness(page, BUSINESS_B);

        await switcher.selectOption({ label: BUSINESS_A });
        await expectCurrentBusiness(page, BUSINESS_A);

        await switcher.selectOption({ label: BUSINESS_B });
        await expectCurrentBusiness(page, BUSINESS_B);

        await page
            .getByRole('navigation', {
                name: 'Workspace navigation',
            })
            .getByRole('link', {
                name: 'Profile & Settings',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/account\/settings$/);

        await expect(
            page.getByRole('heading', {
                name: 'Profile & Settings',
                exact: true,
            }),
        ).toBeVisible();

        await expectCurrentBusiness(page, BUSINESS_B);

        const languageSelect = page.locator(
            'select[name="language_mode"]',
        );

        await expect(languageSelect).toHaveValue('en');

        await languageSelect.selectOption('my');

        await page
            .getByRole('button', {
                name: 'Save settings',
                exact: true,
            })
            .click();

        await expect(languageSelect).toHaveValue('my');

        await expect(
            page.getByRole('heading', {
                name: 'ကိုယ်ရေးအချက်အလက်နှင့် ဆက်တင်များ',
                exact: true,
            }),
        ).toBeVisible();

        await expect(page.getByRole('status')).toHaveText(
            'အကောင့်ဆက်တင်များကို အပ်ဒိတ်လုပ်ပြီးပါပြီ။',
        );

        await expect(
            page.getByRole('navigation', {
                name: 'လုပ်ငန်းအလုပ်ခွင် လမ်းညွှန်',
            }),
        ).toBeVisible();

        await expectCurrentBusiness(page, BUSINESS_B);

        const myanmarSwitcher = await expectBusinessOptions(
            page,
            'လက်ရှိလုပ်ငန်းကို ရွေးချယ်ရန်',
        );

        await myanmarSwitcher.selectOption({ label: BUSINESS_A });
        await expectCurrentBusiness(page, BUSINESS_A);

        await languageSelect.selectOption('mixed');

        await page
            .getByRole('button', {
                name: 'ဆက်တင်များ သိမ်းမည်',
                exact: true,
            })
            .click();

        await expect(languageSelect).toHaveValue('mixed');

        await expect(
            page.getByRole('heading', {
                name: 'Profile & Settings',
                exact: true,
            }),
        ).toBeVisible();

        await expect(page.getByRole('status')).toHaveText(
            'Account settings ကို update လုပ်ပြီးပါပြီ။',
        );

        await expect(
            page.getByRole('navigation', {
                name: 'Business Workspace လမ်းညွှန်',
            }),
        ).toBeVisible();

        await expectCurrentBusiness(page, BUSINESS_A);

        const mixedSwitcher = await expectBusinessOptions(
            page,
            'Current Business ကို ရွေးချယ်ရန်',
        );

        await mixedSwitcher.selectOption({ label: BUSINESS_B });
        await expectCurrentBusiness(page, BUSINESS_B);

        await languageSelect.selectOption('en');

        await page
            .getByRole('button', {
                name: 'Save settings',
                exact: true,
            })
            .click();

        await expect(languageSelect).toHaveValue('en');

        await expect(
            page.getByRole('heading', {
                name: 'Profile & Settings',
                exact: true,
            }),
        ).toBeVisible();

        await expect(page.getByRole('status')).toHaveText(
            'Account settings updated.',
        );

        await expect(
            page.getByRole('navigation', {
                name: 'Workspace navigation',
            }),
        ).toBeVisible();

        await expectCurrentBusiness(page, BUSINESS_B);

        await expectBusinessOptions(
            page,
            'Select current Business',
        );

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

        await expectCurrentBusiness(page, BUSINESS_B);

        await page
            .getByRole('button', {
                name: 'Sign out',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/login$/);

        await expect(
            page.getByRole('heading', {
                name: 'Sign in',
                exact: true,
            }),
        ).toBeVisible();
    },
);

test(
    'mobile accessibility journey: native navigation dialog supports keyboard, Escape, focus return, and touch close',
    async ({ page }, testInfo) => {
        test.skip(
            testInfo.project.name !== 'chromium-mobile',
            'Mobile accessibility journey runs only in the mobile Chromium project.',
        );

        await signIn(page);

        const desktopSidebar = page.locator('aside');

        await expect(desktopSidebar).toBeHidden();

        await expect(
            page.getByText('Current Business', {
                exact: true,
            }),
        ).toBeVisible();

        const trigger = page.getByRole('button', {
            name: 'Open workspace navigation',
            exact: true,
        });

        await expect(trigger).toBeVisible();

        await expect(trigger).toHaveAttribute(
            'aria-controls',
            'mobile-workspace-navigation',
        );

        await expect(trigger).toHaveAttribute(
            'aria-expanded',
            'false',
        );

        await trigger.focus();
        await expect(trigger).toBeFocused();

        await page.keyboard.press('Enter');

        await expect(trigger).toHaveAttribute(
            'aria-expanded',
            'true',
        );

        const dialog = page.getByRole('dialog', {
            name: 'Workspace navigation',
            exact: true,
        });

        await expect(dialog).toBeVisible();

        const homeLink = dialog.getByRole('link', {
            name: 'Home',
            exact: true,
        });

        await expect(homeLink).toHaveAttribute(
            'aria-current',
            'page',
        );

        await expect(
            dialog.getByRole('combobox', {
                name: 'Select current Business',
                exact: true,
            }),
        ).toBeVisible();

        await page.keyboard.press('Escape');

        await expect(dialog).toBeHidden();

        await expect(trigger).toHaveAttribute(
            'aria-expanded',
            'false',
        );

        await expect(trigger).toBeFocused();

        await trigger.tap();

        await expect(dialog).toBeVisible();

        await expect(trigger).toHaveAttribute(
            'aria-expanded',
            'true',
        );

        const closeButton = dialog.getByRole('button', {
            name: 'Close workspace navigation',
            exact: true,
        });

        await expect(closeButton).toBeVisible();

        await closeButton.tap();

        await expect(dialog).toBeHidden();

        await expect(trigger).toHaveAttribute(
            'aria-expanded',
            'false',
        );

        await expect(trigger).toBeFocused();

        await trigger.tap();

        await expect(dialog).toBeVisible();

        await dialog
            .getByRole('link', {
                name: 'Profile & Settings',
                exact: true,
            })
            .tap();

        await expect(page).toHaveURL(/\/account\/settings$/);

        await expect(dialog).toBeHidden();

        await expect(
            page.getByRole('heading', {
                name: 'Profile & Settings',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page.getByRole('button', {
                name: 'Open workspace navigation',
                exact: true,
            }),
        ).toHaveAttribute('aria-expanded', 'false');
    },
);
