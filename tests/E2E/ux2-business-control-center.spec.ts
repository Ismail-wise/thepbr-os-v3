import { expect, test, type Page } from '@playwright/test';

const OWNER_EMAIL = 'f7-owner@example.com';
const BUSINESS = 'F7 Lifecycle Intelligence Business';
const password = process.env.F7_E2E_PASSWORD;

if (!password) {
    throw new Error(
        'F7_E2E_PASSWORD must be provided through the environment.',
    );
}

const signIn = async (page: Page) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(OWNER_EMAIL);
    await page.getByLabel('Password', { exact: true }).fill(password);

    await Promise.all([
        page.waitForURL(
            (url) => url.pathname === '/',
            { waitUntil: 'domcontentloaded' },
        ),
        page
            .getByRole('button', {
                name: 'Sign in',
                exact: true,
            })
            .click(),
    ]);
};

const selectBusiness = async (page: Page) => {
    const viewport = page.viewportSize();

    if (viewport !== null && viewport.width < 1024) {
        await page
            .getByRole('button', {
                name: 'Open workspace navigation',
                exact: true,
            })
            .click();

        const dialog = page.getByRole('dialog', {
            name: 'Workspace navigation',
            exact: true,
        });

        await expect(dialog).toBeVisible();

        const switcher = dialog.getByRole('combobox', {
            name: 'Select current Business',
            exact: true,
        });

        await expect(switcher).toBeVisible();
        await switcher.selectOption({ label: BUSINESS });

        await expect(
            page.locator('header').getByText(BUSINESS, { exact: true }),
        ).toBeVisible();

        return;
    }

    await page
        .locator('aside')
        .locator('#business-switcher-desktop')
        .selectOption({ label: BUSINESS });

    await expect(
        page.locator('header').getByText(BUSINESS, { exact: true }),
    ).toBeVisible();
};

test('UX-2 Business Control Center is guided, tenant-contextual and free of technical identifiers', async ({
    page,
}) => {
    await signIn(page);
    await selectBusiness(page);

    await page.goto('/overview', {
        waitUntil: 'networkidle',
    });

    await expect(
        page.locator('main h1').filter({ hasText: BUSINESS }),
    ).toBeVisible();

    for (const heading of [
        'Needs Your Attention',
        'Business Health / Readiness',
        'Critical Business Snapshot',
        'Next Best Actions',
        'Operating Areas',
        'Upcoming',
        'Recent Activity',
    ]) {
        await expect(
            page.locator('main h2').filter({ hasText: heading }),
        ).toBeVisible();
    }

    const mainText = await page.locator('main').innerText();

    for (const forbidden of [
        /\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i,
        /\b[a-f0-9]{64}\b/i,
        /current_effective_source/i,
        /formal_record_version/i,
        /authority_snapshot/i,
        /proposal[_ ]?id/i,
        /source[_ ]?id/i,
    ]) {
        expect(mainText).not.toMatch(forbidden);
    }

    const dimensions = await page.evaluate(() => ({
        innerWidth: window.innerWidth,
        scrollWidth: document.documentElement.scrollWidth,
    }));

    expect(dimensions.scrollWidth).toBeLessThanOrEqual(
        dimensions.innerWidth + 1,
    );
});
