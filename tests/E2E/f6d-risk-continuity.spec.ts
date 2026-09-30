import { expect, test, type Page } from '@playwright/test';

const E2E_EMAIL = 'f6d-browser@example.com';
const BUSINESS = 'F6D Risk Continuity Business';

const password = process.env.F6D_E2E_PASSWORD;

if (!password) {
    throw new Error(
        'F6D_E2E_PASSWORD must be provided through the environment.',
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
    'F6D Risk and Continuity command centers preserve restricted and authority boundaries',
    async ({ page }, testInfo) => {
        test.skip(
            testInfo.project.name !== 'chromium-desktop',
            'F6D deterministic journey runs only in desktop Chromium.',
        );

        await signIn(page);
        await switchBusiness(page);

        const navigation = page.getByRole('navigation', {
            name: 'Workspace navigation',
        });

        await navigation
            .getByRole('link', {
                name: 'Governance',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/governance$/);

        const governanceRulesLink = page.getByRole('link', {
            name: 'Rules & Authority',
            exact: true,
        });

        await expect(governanceRulesLink).toBeVisible();

        await governanceRulesLink.click();

        await expect(page).toHaveURL(
            /\/governance\/rules$/,
        );

        await expect(
            page.getByRole('heading', {
                name: 'Governance Rules & Authority',
                exact: true,
            }),
        ).toBeVisible();

        await navigation
            .getByRole('link', {
                name: 'Risk & Protection',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/risk$/);
        await expect(
            page.getByRole('heading', {
                name: 'Risk & Protection',
                exact: true,
            }),
        ).toBeVisible();
        await expect(
            page.getByText(
                'Risk ownership is operational responsibility, not Governance authority. Restricted risks, incidents and evidence remain default-deny.',
                { exact: true },
            ),
        ).toBeVisible();
        await expect(
            page.getByText('No Effective Risk Register yet.', {
                exact: true,
            }),
        ).toBeVisible();

        const riskDraft = page
            .locator('details')
            .filter({
                has: page.getByText(
                    'Create Risk Register Draft / Amendment',
                    { exact: true },
                ),
            });

        await riskDraft.locator('summary').click();

        const riskReviewDue = riskDraft.getByLabel(
            'Review due',
            { exact: true },
        );

        await expect(riskReviewDue).toHaveValue('');

        await riskReviewDue.evaluate((node) => {
            const input = node as HTMLInputElement;

            input.value = '2026-09-30';

            window.dispatchEvent(
                new Event('pageshow'),
            );
        });

        await expect(riskReviewDue).toHaveValue('');

        await riskReviewDue.evaluate((node) => {
            const input = node as HTMLInputElement;

            input.addEventListener(
                'focus',
                () => {
                    input.value = '2026-10-01';
                },
                { once: true },
            );
        });

        await riskReviewDue.focus();

        await expect(riskReviewDue).toHaveValue('');

        await riskReviewDue.fill('2026-12-30');

        await expect(riskReviewDue).toHaveValue(
            '2026-12-30',
        );

        await riskReviewDue.evaluate((node) => {
            const input = node as HTMLInputElement;

            input.addEventListener(
                'keydown',
                () => {
                    input.value = '2026-10-01';
                },
                { once: true },
            );
        });

        await riskReviewDue.press('Backspace');

        await expect(riskReviewDue).toHaveValue('');

        await expect(
            riskDraft.getByText(
                'Never enter passwords, PINs, OTPs, tokens, secret credentials or recovery secrets.',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(page.locator('input[type="password"]')).toHaveCount(0);

        await navigation
            .getByRole('link', {
                name: 'Continuity',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/continuity$/);
        await expect(
            page.getByRole('heading', {
                name: 'Business Continuity',
                exact: true,
            }),
        ).toBeVisible();
        await expect(
            page.getByText(
                'Backup is temporary; Successor is long-term. Emergency access never creates permanent System or Governance authority.',
                { exact: true },
            ),
        ).toBeVisible();
        await expect(
            page.getByText('No Effective Continuity Plan yet.', {
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page.getByText(
                'A Current Effective Operations Register is required before you can prepare a Continuity Plan.',
                { exact: true },
            ),
        ).toBeVisible();

        const operationsPrerequisite = page.locator('section').filter({
            has: page.getByText(
                'A Current Effective Operations Register is required before you can prepare a Continuity Plan.',
                { exact: true },
            ),
        });

        await expect(operationsPrerequisite).toHaveCount(1);
        await expect(
            operationsPrerequisite.getByRole('link', {
                name: 'Open Operations',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page
                .locator('details')
                .filter({
                    has: page.getByText(
                        'Create Continuity Plan Draft / Amendment',
                        { exact: true },
                    ),
                }),
        ).toHaveCount(0);

        await expect(page.locator('input[type="password"]')).toHaveCount(0);
        await expect(
            page.getByRole('button', {
                name: /apply scenario|grant .*authority|approve .*authority/i,
            }),
        ).toHaveCount(0);
    },
);
