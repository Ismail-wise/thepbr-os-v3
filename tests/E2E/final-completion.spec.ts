import { expect, test, type Page } from '@playwright/test';

const OWNER_EMAIL = 'f7-owner@example.com';
const BUSINESS = 'F7 Lifecycle Intelligence Business';
const password = process.env.F7_E2E_PASSWORD;

if (!password) {
    throw new Error('F7_E2E_PASSWORD must be provided through the environment.');
}

const routes = [
    '/', '/account/businesses', '/account/work', '/account/notifications',
    '/account/approvals', '/account/signatures', '/account/settings',
    '/overview', '/search', '/ai', '/health', '/reports',
    '/businesses/create', '/workspace/access', '/formation',
    '/business/legal-structure', '/partnership', '/governance',
    '/governance/rules', '/governance/meetings', '/operations',
    '/finance', '/rewards', '/risk', '/continuity', '/conflict',
    '/changes/partner-changes', '/changes/exit', '/changes/closure',
    '/records/documents', '/records/activity', '/import',
    '/records/portability',
] as const;

const uuidPattern =
    /\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i;
const technicalHashPattern = /\b[0-9a-f]{64}\b/i;

const forbiddenDefaultLabels = [
    'Manifest hash',
    'Evidence ID',
    'Authority Snapshot ID',
    'Emergency Authority Grant ID',
];

const signInAndSelectBusiness = async (page: Page) => {
    await page.goto('/login');
    await page.locator('input[name="email"]').fill(OWNER_EMAIL);
    await page.locator('input[name="password"]').fill(password as string);

    await Promise.all([
        page.waitForURL((url) => url.pathname === '/', {
            waitUntil: 'domcontentloaded',
        }),
        page.getByRole('button', { name: 'Sign in', exact: true }).click(),
    ]);

    const switcher = page.locator('#business-switcher-desktop');
    await switcher.selectOption({ label: BUSINESS });
    await expect(
        page.locator('header').first().getByText(BUSINESS, { exact: true }),
    ).toBeVisible();
};

const setLanguageMode = async (
    page: Page,
    mode: 'en' | 'my' | 'mixed',
) => {
    await page.goto('/account/settings', { waitUntil: 'domcontentloaded' });

    const form = page
        .locator('form')
        .filter({ has: page.locator('#language_mode') });

    await form.locator('#language_mode').selectOption(mode);
    await form.locator('button[type="submit"]').click();
    await expect(form.locator('#language_mode')).toHaveValue(mode);
};

const verifyRoute = async (page: Page, route: string) => {
    const response = await page.goto(route, { waitUntil: 'domcontentloaded' });
    expect(response?.status() ?? 500, route).toBeLessThan(400);
    await expect(page.locator('.pbr-page-frame')).toBeVisible();

    const visibleText = await page.locator('body').innerText();
    expect(visibleText, route).not.toMatch(uuidPattern);
    expect(visibleText, route).not.toMatch(technicalHashPattern);

    for (const label of forbiddenDefaultLabels) {
        expect(visibleText, `${route}: ${label}`).not.toContain(label);
    }

    const overflow = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
    }));

    expect(
        overflow.scrollWidth,
        `${route} should not overflow horizontally`,
    ).toBeLessThanOrEqual(overflow.clientWidth + 1);
};

for (const viewport of [
    { name: 'desktop-1440', width: 1440, height: 1000 },
    { name: 'tablet-768', width: 768, height: 1024 },
    { name: 'mobile-390', width: 390, height: 844 },
]) {
    test(`Final Hybrid major routes are UAT-ready at ${viewport.name}`, async ({
        page,
    }, testInfo) => {
        test.skip(
            testInfo.project.name !== 'chromium-desktop',
            'Final completion verification owns its exact viewports.',
        );
        test.setTimeout(180_000);
        await page.setViewportSize({
            width: 1440,
            height: 1000,
        });
        await signInAndSelectBusiness(page);
        await page.setViewportSize({
            width: viewport.width,
            height: viewport.height,
        });

        for (const route of routes) {
            await verifyRoute(page, route);
        }

        await page.goto('/overview', { waitUntil: 'domcontentloaded' });
        await page.screenshot({
            path: `test-results/final-${viewport.name}-overview.png`,
            fullPage: true,
        });

        await page.goto('/governance', { waitUntil: 'domcontentloaded' });
        await page.screenshot({
            path: `test-results/final-${viewport.name}-governance.png`,
            fullPage: true,
        });

        await page.goto('/changes/exit', { waitUntil: 'domcontentloaded' });
        await page.screenshot({
            path: `test-results/final-${viewport.name}-exit.png`,
            fullPage: true,
        });
    });
}

test('Final Hybrid localization modes stay usable on key surfaces', async ({
    page,
}, testInfo) => {
    test.skip(
        testInfo.project.name !== 'chromium-desktop',
        'Final completion localization verification runs in desktop Chromium.',
    );
    test.setTimeout(90_000);

    await signInAndSelectBusiness(page);

    for (const mode of [
        { value: 'my' as const, finance: 'ဘဏ္ဍာရေးနှင့် ထိန်းချုပ်မှု' },
        {
            value: 'mixed' as const,
            finance: 'Finance & Control · ဘဏ္ဍာရေးနှင့် ထိန်းချုပ်မှု',
        },
        { value: 'en' as const, finance: 'Finance & Control' },
    ]) {
        await setLanguageMode(page, mode.value);
        await page.goto('/finance', { waitUntil: 'domcontentloaded' });
        await expect(
            page.getByRole('heading', {
                name: mode.finance,
                exact: true,
                level: 1,
            }),
        ).toBeVisible();
        await verifyRoute(page, '/finance');
    }
});