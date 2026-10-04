import { expect, test, type Page } from '@playwright/test';

const E2E_EMAIL = 'f1-a13-browser@example.com';
const e2ePassword = process.env.F1_E2E_PASSWORD;

if (!e2ePassword) {
    throw new Error(
        'F1_E2E_PASSWORD must be provided through the environment.',
    );
}

const E2E_PASSWORD: string = e2ePassword;

const signIn = async (page: Page) => {
    await page.goto('/login');

    await page.locator('input[name="email"]').fill(E2E_EMAIL);
    await page.locator('input[name="password"]').fill(E2E_PASSWORD);

    await page
        .getByRole('button', { name: 'Sign in', exact: true })
        .click();

    await expect(page).toHaveURL(/\/$/);
};

test('Partner Dynamics personal assessment starts and resumes privately', async ({
    page,
}) => {
    await signIn(page);

    await page.goto('/partner-dynamics');

    await expect(
        page.getByRole('heading', {
            name: 'Partner Dynamics',
            exact: true,
        }),
    ).toBeVisible();

    await expect(
        page.getByText(
            /raw questionnaire answers are private to you/i,
        ),
    ).toBeVisible();

    await page
        .getByRole('button', {
            name: 'Start assessment',
            exact: true,
        })
        .click();

    await expect(page).toHaveURL(
        /\/partner-dynamics\/assessments\/[^/]+\/steps\/1$/,
    );

    await expect(
        page.getByText('Step 1 / 5', { exact: true }),
    ).toBeVisible();

    for (let question = 1; question <= 8; question += 1) {
        await page
            .locator(
                `input[name="answers[${question}]"][value="3"]`,
            )
            .check();
    }

    await page
        .getByRole('button', {
            name: 'Save and continue',
            exact: true,
        })
        .click();

    await expect(page).toHaveURL(
        /\/partner-dynamics\/assessments\/[^/]+\/steps\/2$/,
    );

    await expect(
        page.getByText('Step 2 / 5', { exact: true }),
    ).toBeVisible();

    await page.goto('/partner-dynamics');

    await expect(
        page.getByRole('heading', {
            name: 'Continue your assessment',
            exact: true,
        }),
    ).toBeVisible();

    await expect(
        page.getByText('Private draft saved to your account.', {
            exact: true,
        }),
    ).toBeVisible();
});
