import { expect, test, type Page } from '@playwright/test';

const E2E_EMAIL = 'f4-browser@example.com';
const NEW_BUSINESS = 'F4 New Business';
const EXISTING_BUSINESS = 'F4 Existing Business';

const password = process.env.F4_E2E_PASSWORD;

if (!password) {
    throw new Error(
        'F4_E2E_PASSWORD must be provided through the environment.',
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

const switchBusiness = async (page: Page, business: string) => {
    const switcher = page
        .locator('aside')
        .getByRole('combobox', {
            name: 'Select current Business',
            exact: true,
        });

    await switcher.selectOption({ label: business });

    await expect(
        page.locator('header').first().getByText(business, {
            exact: true,
        }),
    ).toBeVisible();
};

test(
    'F4 formation workspace distinguishes New and Existing Business journeys and preserves scenario-only Capital',
    async ({ page }, testInfo) => {
        test.skip(
            testInfo.project.name !== 'chromium-desktop',
            'F4 deterministic journey runs only in desktop Chromium.',
        );

        await signIn(page);
        await switchBusiness(page, NEW_BUSINESS);

        await page
            .getByRole('navigation', {
                name: 'Workspace navigation',
            })
            .getByRole('link', {
                name: 'Formation & Capital',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/formation$/);

        await expect(
            page.getByRole('heading', {
                name: 'Formation & Capital',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page.getByText('New Business Formation', {
                exact: true,
            }),
        ).toBeVisible();

        await page
            .getByRole('button', {
                name: 'Business Model Canvas',
                exact: true,
            })
            .click();

        for (const block of [
            'Customer Segments',
            'Value Propositions',
            'Channels',
            'Customer Relationships',
            'Revenue Streams',
            'Key Resources',
            'Key Activities',
            'Key Partnerships',
            'Cost Structure',
        ]) {
            await expect(
                page.getByRole('button', {
                    name: new RegExp(block),
                }),
            ).toBeVisible();
        }

        await page
            .getByRole('button', {
                name: 'Capital',
                exact: true,
            })
            .click();

        await expect(
            page.getByText('6500.00', { exact: true }),
        ).toBeVisible();

        await expect(
            page.getByText('4000.00', { exact: true }),
        ).toBeVisible();

        await expect(
            page.getByText(
                'Scenario is planning only and never changes live truth.',
                { exact: true },
            ),
        ).toBeVisible();

        await switchBusiness(page, EXISTING_BUSINESS);

        await expect(
            page.getByText('Existing Business Baseline', {
                exact: true,
            }).first(),
        ).toBeVisible();

        await page
            .getByRole('button', {
                name: 'Business Model Canvas',
                exact: true,
            })
            .click();

        await expect(
            page.getByRole('button', {
                name: /Customer Segments/,
            }),
        ).toContainText('Existing customer base');

        await page
            .getByRole('button', {
                name: 'Baseline',
                exact: true,
            })
            .click();

        await expect(
            page.getByText('250000.00 USD', {
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page.getByText('reviewed', {
                exact: true,
            }),
        ).toBeVisible();
    },
);
