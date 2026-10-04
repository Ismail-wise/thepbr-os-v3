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

    await page.locator('input[name="email"]').fill(E2E_EMAIL);
    await page.locator('input[name="password"]').fill(password);

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

        const newBusinessJourney = page.getByRole('navigation', {
            name: 'Guided setup journey',
            exact: true,
        });

        for (const step of [
            'Idea',
            'Business Model Canvas',
            'Validation',
            'Feasibility',
            'Partnership Fit',
            'Go / Revise / Hold / No-Go',
            'Partner Setup',
        ]) {
            await expect(
                newBusinessJourney.getByRole('button', {
                    name: new RegExp(step.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')),
                }),
            ).toBeVisible();
        }

        await newBusinessJourney
            .getByRole('button', {
                name: /Go \/ Revise \/ Hold \/ No-Go/,
            })
            .click();

        await expect(
            page.getByRole('heading', {
                name: 'Go / Revise / Hold / No-Go',
                exact: true,
            }),
        ).toBeVisible();

        await newBusinessJourney
            .getByRole('button', {
                name: /Business Model Canvas/,
            })
            .click();

        await expect(
            page.getByRole('heading', {
                name: 'Build how this Business will work',
                exact: true,
            }),
        ).toBeVisible();

        const businessModelJourney = page.getByRole('navigation', {
            name: 'Business Model guided journey',
            exact: true,
        });

        for (const step of [
            'Purpose',
            'Customer',
            'Product / service',
            'Market & location',
            'Revenue model',
            'Pricing & break-even',
            'Sales channels',
            'Customer relationship',
            'Operating model',
            'Delivery engine',
            'Cost structure',
            'Scalability',
            'Boundaries',
            'First 12 months',
            'Review',
        ]) {
            await expect(
                businessModelJourney.getByRole('button', {
                    name: step,
                    exact: true,
                }),
            ).toBeVisible();
        }

        await expect(
            page.getByText('Draft ready', { exact: true }),
        ).toBeVisible();

        await page
            .getByRole('button', {
                name: 'Capital',
                exact: true,
            })
            .click();

        const capitalJourney = page.getByRole('navigation', {
            name: 'Capital Planning Workflow',
            exact: true,
        });

        for (const step of [
            'Startup Cost Plan',
            'Initial Assets & Opening Inventory',
            'Working Capital Forecast',
            'Contingency Reserve',
            'Funding Position & Gap',
            'Capital Rule & Allocation',
        ]) {
            await expect(
                capitalJourney.getByRole('button', {
                    name: step,
                    exact: true,
                }),
            ).toBeVisible();
        }

        await expect(
            page.getByRole('heading', {
                name: 'Live Capital Position',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page.getByText('38.46%', { exact: true }),
        ).toBeVisible();

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

        const existingBusinessJourney = page.getByRole('navigation', {
            name: 'Guided setup journey',
            exact: true,
        });

        for (const step of [
            'Business Profile',
            'Current BMC',
            'Financial Baseline',
            'Assets & Liabilities',
            'Valuation',
            'Existing Owners',
            'Obligations & Risks',
            'PBR Gap',
            'Conversion Plan',
            'Partner Setup',
        ]) {
            await expect(
                existingBusinessJourney.getByRole('button', {
                    name: new RegExp(step.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')),
                }),
            ).toBeVisible();
        }

        await existingBusinessJourney
            .getByRole('button', {
                name: /Current BMC/,
            })
            .click();

        await expect(
            page.getByRole('heading', {
                name: 'Build how this Business will work',
                exact: true,
            }),
        ).toBeVisible();

        const existingBusinessModelJourney = page.getByRole(
            'navigation',
            {
                name: 'Business Model guided journey',
                exact: true,
            },
        );

        await existingBusinessModelJourney
            .getByRole('button', {
                name: 'Customer',
                exact: true,
            })
            .click();

        await expect(page.locator('textarea').first()).toHaveValue(
            'Existing customer base',
        );

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
            page.getByRole('cell', {
                name: 'reviewed',
                exact: true,
            }),
        ).toBeVisible();
    },
);
