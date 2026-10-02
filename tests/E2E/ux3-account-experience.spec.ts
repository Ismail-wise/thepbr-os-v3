import { expect, test, type Page } from '@playwright/test';

const OWNER_EMAIL = 'f7-owner@example.com';
const PRIMARY_BUSINESS = 'F7 Lifecycle Intelligence Business';
const SECONDARY_BUSINESS = 'F7 Foreign Business';
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

const expectNoTechnicalIdentifiers = async (page: Page) => {
    const mainText = await page.locator('main').innerText();

    for (const forbidden of [
        /\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i,
        /\b[a-f0-9]{64}\b/i,
        /authority_snapshot/i,
        /formal_record_version/i,
        /proposal[_ ]?id/i,
        /membership[_ ]?id/i,
        /document[_ ]?hash/i,
    ]) {
        expect(mainText).not.toMatch(forbidden);
    }
};

test('UX-3 account experience is action-first, cross-Business safe and responsive', async ({
    page,
}) => {
    await signIn(page);

    await expect(
        page.getByRole('heading', {
            name: 'Account Home',
            exact: true,
        }),
    ).toBeVisible();

    await expect(
        page.getByRole('heading', {
            name: 'Needs you now',
            exact: true,
        }),
    ).toBeVisible();

    await expect(page.getByText(PRIMARY_BUSINESS, { exact: true })).toBeVisible();
    await expect(page.getByText(SECONDARY_BUSINESS, { exact: true })).toBeVisible();
    await expect(
        page.getByText('Nothing needs your attention right now', {
            exact: true,
        }),
    ).toBeVisible();

    await expectNoTechnicalIdentifiers(page);

    await page
        .getByRole('link', {
            name: 'My Businesses',
            exact: true,
        })
        .click();

    await expect(
        page.getByRole('heading', {
            name: 'My Businesses',
            exact: true,
        }),
    ).toBeVisible();
    await expect(page.getByText(PRIMARY_BUSINESS, { exact: true })).toBeVisible();
    await expect(page.getByText(SECONDARY_BUSINESS, { exact: true })).toBeVisible();

    await page
        .getByRole('link', {
            name: 'My Work',
            exact: true,
        })
        .click();

    await expect(
        page.getByRole('heading', {
            name: 'My Work',
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByText('No work is currently assigned to you', {
            exact: true,
        }),
    ).toBeVisible();

    await page
        .getByRole('link', {
            name: 'My Approvals',
            exact: true,
        })
        .click();

    await expect(
        page.getByText('No approvals are waiting for you', {
            exact: true,
        }),
    ).toBeVisible();

    await page
        .getByRole('link', {
            name: 'My Signatures',
            exact: true,
        })
        .click();

    await expect(
        page.getByText('No signatures are waiting for you', {
            exact: true,
        }),
    ).toBeVisible();

    await expectNoTechnicalIdentifiers(page);

    const dimensions = await page.evaluate(() => ({
        innerWidth: window.innerWidth,
        scrollWidth: document.documentElement.scrollWidth,
    }));

    expect(dimensions.scrollWidth).toBeLessThanOrEqual(
        dimensions.innerWidth + 1,
    );
});
