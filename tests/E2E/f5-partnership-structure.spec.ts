import { expect, test, type Page } from '@playwright/test';

const E2E_EMAIL = 'f5-browser@example.com';
const BUSINESS = 'F5 Partnership Business';
const PARTNER = 'Prepared Partner';

const password = process.env.F5_E2E_PASSWORD;

if (!password) {
    throw new Error(
        'F5_E2E_PASSWORD must be provided through the environment.',
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

    await expect(
        page.getByText('Signed-in identity', {
            exact: true,
        }),
    ).toBeVisible();
};

const switchBusiness = async (
    page: Page,
    business: string,
) => {
    const switcher = page
        .locator('aside')
        .getByRole('combobox', {
            name: 'Select current Business',
            exact: true,
        });

    await switcher.selectOption({
        label: business,
    });

    await expect(
        page
            .locator('header')
            .first()
            .getByText(business, {
                exact: true,
            }),
    ).toBeVisible();
};

test(
    'F5 partnership workspace preserves Partner, Contribution and scenario boundaries',
    async ({ page }, testInfo) => {
        test.skip(
            testInfo.project.name !== 'chromium-desktop',
            'F5 deterministic journey runs only in desktop Chromium.',
        );

        await signIn(page);
        await switchBusiness(page, BUSINESS);

        await page
            .getByRole('navigation', {
                name: 'Workspace navigation',
            })
            .getByRole('link', {
                name: 'Partners & Ownership',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/partnership$/);

        await expect(
            page.getByRole('heading', {
                name: 'Partners & Ownership',
                exact: true,
            }),
        ).toBeVisible();

        const partnerRow = page
            .getByRole('row')
            .filter({
                has: page.getByText(PARTNER, {
                    exact: true,
                }),
            })
            .filter({
                hasText: 'in_review',
            })
            .filter({
                hasText: 'visionary',
            });

        await expect(partnerRow).toBeVisible();

        await expect(
            partnerRow.getByText(PARTNER, {
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            partnerRow.getByText('in_review', {
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            partnerRow.getByText('visionary', {
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page.getByText(
                'PartnerDynamics is a reference for partnership understanding. It never automatically determines equity, governance authority or system permissions.',
                { exact: true },
            ),
        ).toBeVisible();

        await page
            .getByRole('button', {
                name: 'Contribution Register',
                exact: true,
            })
            .click();

        await expect(
            page.getByText(
                'Prepared cash contribution',
                { exact: true },
            ),
        ).toBeVisible();

        await page
            .getByRole('button', {
                name: 'Ownership',
                exact: true,
            })
            .click();

        await expect(
            page.getByText(
                'Ownership scenarios are planning only. They never change official ownership directly.',
                { exact: true },
            ).first(),
        ).toBeVisible();

        await expect(
            page.getByText(
                'No Effective Ownership Register yet.',
                { exact: true },
            ),
        ).toBeVisible();

        const workflow = page.getByRole('region', {
            name: 'Workflow Actions',
        });

        await workflow
            .locator('summary')
            .filter({
                hasText: /^Add Contribution$/,
            })
            .click();

        await workflow
            .getByLabel('Partner', {
                exact: true,
            })
            .last()
            .selectOption({
                label: PARTNER,
            });

        await workflow
            .getByLabel('Type', {
                exact: true,
            })
            .selectOption('cash');

        await workflow
            .getByLabel('Proposed value', {
                exact: true,
            })
            .fill('2500.00');

        await workflow
            .getByLabel('Description', {
                exact: true,
            })
            .fill('Browser cash contribution');

        await workflow
            .getByLabel('Amount committed', {
                exact: true,
            })
            .fill('2500.00');

        await workflow
            .getByRole('button', {
                name: 'Add Contribution',
                exact: true,
            })
            .click();

        await expect(
            page.getByText(
                'Browser cash contribution',
                { exact: true },
            ),
        ).toBeVisible();

        await workflow
            .locator('summary')
            .filter({
                hasText: /^Create Ownership Scenario$/,
            })
            .click();

        await workflow
            .getByLabel('Scenario name', {
                exact: true,
            })
            .fill('Must Not Apply Scenario');

        await workflow
            .getByRole('button', {
                name: 'Create Ownership Scenario',
                exact: true,
            })
            .click();

        await expect(
            workflow.getByText(
                /requires at least one Accepted Contribution/i,
            ),
        ).toBeVisible();

        await expect(
            page.getByText(
                'No Effective Ownership Register yet.',
                { exact: true },
            ),
        ).toBeVisible();

        await page
            .getByRole('link', {
                name: 'Governance',
                exact: true,
            })
            .first()
            .click();

        await expect(page).toHaveURL(/\/governance$/);
    },
);
