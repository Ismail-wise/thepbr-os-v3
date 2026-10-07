import { expect, test, type Page } from '@playwright/test';

const EMAIL = 'f5-browser@example.com';
const BUSINESS = 'F5 Partnership Business';
const password = process.env.F5_E2E_PASSWORD;

if (!password) {
    throw new Error(
        'F5_E2E_PASSWORD must be provided through the environment.',
    );
}

const signIn = async (page: Page) => {
    await page.goto('/login');
    await page.locator('input[name="email"]').fill(EMAIL);
    await page.locator('input[name="password"]').fill(password);

    await Promise.all([
        page.waitForURL(
            (url) => url.pathname === '/',
            {
                waitUntil: 'domcontentloaded',
                timeout: 10_000,
            },
        ),
        page
            .getByRole('button', {
                name: 'Sign in',
                exact: true,
            })
            .click(),
    ]);
};

const switchBusiness = async (page: Page) => {
    const switcher = page
        .locator('aside')
        .getByRole('combobox', {
            name: 'Select current Business',
            exact: true,
        });

    await switcher.selectOption({
        label: BUSINESS,
    });

    await expect(
        page
            .locator('header')
            .first()
            .getByText(BUSINESS, {
                exact: true,
            }),
    ).toBeVisible();
};

test(
    'One-Day MVP master journey stays connected through the final review control center',
    async ({ page }) => {
        test.setTimeout(120_000);

        await signIn(page);
        await switchBusiness(page);

        await page
            .getByRole('navigation', {
                name: 'Workspace navigation',
            })
            .getByRole('link', {
                name: 'Business Control Center',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/overview$/);

        const masterJourney = page.getByRole(
            'navigation',
            {
                name: 'Master Business Journey',
                exact: true,
            },
        );

        for (const label of [
            'Governance & Decision Rules',
            'Roles & Operations',
            'Finance & Control',
            'Salary, Bonus, Profit & Distribution',
            'Risk, Insurance & Protection',
            'Share Transfer & New Partner',
            'Exit & Buyout',
            'Conflict & Resolution',
            'Continuity & Succession',
            'Closure',
            'Implementation & Review Control Center',
        ]) {
            await expect(
                masterJourney.getByRole('button', {
                    name: label,
                }),
            ).toBeVisible();
        }

        await page.goto('/partner-dynamics');

        const partnerDynamicsGuide = page.locator(
            '[data-grade6-mvp-guide]',
        );

        await expect(
            partnerDynamicsGuide.getByRole('heading', {
                name: 'Partner Dynamics',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            partnerDynamicsGuide.getByRole('link', {
                name: 'Continue to Capital',
                exact: true,
            }),
        ).toHaveAttribute(
            'href',
            '/formation?step=capital',
        );

        await page.goto('/governance');

        const journey = [
            {
                title: 'Governance / Decision Rules',
                next: 'Continue to Roles / Operations',
                path: /\/operations$/,
            },
            {
                title: 'Roles / Operations',
                next: 'Continue to Finance & Control',
                path: /\/finance$/,
            },
            {
                title: 'Finance & Control',
                next: 'Continue to Salary / Bonus / Profit',
                path: /\/rewards$/,
            },
            {
                title: 'Salary / Bonus / Profit Distribution',
                next: 'Continue to Risk / Insurance / Protection',
                path: /\/risk$/,
            },
            {
                title: 'Risk / Insurance / Protection',
                next: 'Continue to Share Transfer / New Partner',
                path: /\/changes\/partner-changes$/,
            },
            {
                title: 'Share Transfer / New Partner',
                next: 'Continue to Exit / Buyout',
                path: /\/changes\/exit$/,
            },
            {
                title: 'Exit / Buyout',
                next: 'Continue to Conflict / Resolution',
                path: /\/conflict$/,
            },
            {
                title: 'Conflict / Resolution',
                next: 'Continue to Continuity / Succession',
                path: /\/continuity$/,
            },
            {
                title: 'Continuity / Succession',
                next: 'Continue to Closure',
                path: /\/changes\/closure$/,
            },
            {
                title: 'Closure',
                next: 'Go to Implementation & Review Control Center',
                path: /\/overview$/,
            },
        ];

        for (const step of journey) {
            const guide = page.locator(
                '[data-grade6-mvp-guide]',
            );

            await expect(
                guide.getByRole('heading', {
                    name: step.title,
                    exact: true,
                }),
            ).toBeVisible();

            for (const prompt of [
                'What am I doing?',
                'Why does this matter?',
                'What does the system already know?',
                'What do I need to decide?',
                'What happens next?',
            ]) {
                await expect(
                    guide.getByText(prompt, {
                        exact: true,
                    }),
                ).toBeVisible();
            }

            await guide
                .getByRole('link', {
                    name: step.next,
                    exact: true,
                })
                .click();

            await expect(page).toHaveURL(step.path);
        }

        const finalGuide = page.locator(
            '[data-grade6-mvp-guide]',
        );

        await expect(
            finalGuide.getByRole('heading', {
                name: 'Implementation & Review Control Center',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            finalGuide.getByText(
                'What am I doing?',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            finalGuide.getByText(
                'What happens next?',
                { exact: true },
            ),
        ).toBeVisible();
    },
);
