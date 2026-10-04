import { expect, test, type Page } from '@playwright/test';

const E2E_EMAIL = 'f6b-browser@example.com';
const BUSINESS = 'F6B Operations Business';

const password = process.env.F6B_E2E_PASSWORD;

if (!password) {
    throw new Error(
        'F6B_E2E_PASSWORD must be provided through the environment.',
    );
}

const signIn = async (page: Page) => {
    await page.goto('/login');
    await page.locator('input[name="email"]').fill(E2E_EMAIL);
    await page.locator('input[name="password"]').fill(password);

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
    'F6B Operations content review transitions once and hydrates current state',
    async ({ page }, testInfo) => {
        test.skip(
            testInfo.project.name !== 'chromium-desktop',
            'F6B deterministic journey runs only in desktop Chromium.',
        );

        await signIn(page);
        await switchBusiness(page);

        await page
            .getByRole('navigation', {
                name: 'Workspace navigation',
            })
            .getByRole('link', {
                name: 'Operations',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/operations$/);

        await expect(
            page.getByRole('heading', {
                name: 'Operations',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page.getByText('Delivery Control', { exact: true }),
        ).toBeVisible();

        const versionRow = page
            .getByRole('row')
            .filter({
                has: page.getByText('v1', {
                    exact: true,
                }),
            });

        await expect(versionRow).toContainText(
            'ready_for_review',
        );

        const underReview = versionRow.getByRole('button', {
            name: 'Under Review',
            exact: true,
        });

        await expect(underReview).toBeVisible();
        await underReview.click();

        await expect(versionRow).toContainText('under_review');

        await expect(
            versionRow.getByRole('button', {
                name: 'Under Review',
                exact: true,
            }),
        ).toHaveCount(0);

        await expect(
            versionRow.getByRole('button', {
                name: 'Content Approved',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            versionRow.getByRole('button', {
                name: 'Request Changes',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            versionRow.getByText(
                /Invalid formal record workflow transition/i,
            ),
        ).toHaveCount(0);
    },
);