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
                hasText: 'visionary',
            });

        await expect(partnerRow).toBeVisible();

        await expect(
            partnerRow.getByText(PARTNER, {
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            partnerRow.getByText('Not started', {
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

        const workflow = page.getByRole('region', {
            name: 'Workflow Actions',
        });

        const ddSummary = workflow
            .locator('summary')
            .filter({
                hasText: /^Due Diligence$/,
            });

        const ddWorkflow = ddSummary.locator('..');

        if ((await ddWorkflow.getAttribute('open')) === null) {
            await ddSummary.click();
        }

        const ddPartner = ddWorkflow.getByLabel('Partner', {
            exact: true,
        });
        const ddStatus = ddWorkflow.getByLabel('Status', {
            exact: true,
        });
        const ddRisk = ddWorkflow.getByLabel('Risk', {
            exact: true,
        });
        const ddRevision = ddWorkflow.getByLabel('Revision', {
            exact: true,
        });
        const ddIdentity = ddWorkflow.getByLabel(
            'Identity / legal information',
            { exact: true },
        );
        const ddBackground = ddWorkflow.getByLabel(
            'Background summary',
            { exact: true },
        );
        const ddSubmit = ddWorkflow.getByRole('button', {
            name: 'Due Diligence',
            exact: true,
        });

        await ddPartner.selectOption({
            label: PARTNER,
        });

        await expect(ddStatus).toHaveValue('draft');
        await expect(ddRevision).toHaveValue('0');
        await expect(ddRevision).toHaveAttribute('readonly', '');

        await ddIdentity.fill('Browser identity evidence');
        await ddBackground.fill('Browser background evidence');

        const draftRequestPromise = page.waitForRequest(
            (request) =>
                request.method() === 'PUT'
                && /\/partnership\/partners\/[^/]+\/due-diligence$/.test(
                    new URL(request.url()).pathname,
                ),
        );

        await ddSubmit.click();

        const draftRequest = await draftRequestPromise;
        const draftPayload = draftRequest.postDataJSON() as {
            case_id?: string | null;
            expected_revision: number;
            status: string;
        };

        expect(draftPayload.case_id ?? '').toBe('');
        expect(draftPayload.expected_revision).toBe(0);
        expect(draftPayload.status).toBe('draft');

        await expect(
            partnerRow.getByText('draft', {
                exact: true,
            }),
        ).toBeVisible();

        await page.reload();

        if ((await ddWorkflow.getAttribute('open')) === null) {
            await ddSummary.click();
        }

        await ddPartner.selectOption({
            label: PARTNER,
        });

        await expect(ddRevision).toHaveValue('1');
        await expect(ddStatus).toHaveValue('draft');
        await expect(ddIdentity).toHaveValue('Browser identity evidence');
        await expect(ddBackground).toHaveValue(
            'Browser background evidence',
        );

        await ddStatus.selectOption('in_review');
        await ddRisk.selectOption('moderate');

        const inReviewRequestPromise = page.waitForRequest(
            (request) =>
                request.method() === 'PUT'
                && /\/partnership\/partners\/[^/]+\/due-diligence$/.test(
                    new URL(request.url()).pathname,
                ),
        );

        await ddSubmit.click();

        const inReviewRequest = await inReviewRequestPromise;
        const inReviewPayload = inReviewRequest.postDataJSON() as {
            case_id: string;
            expected_revision: number;
            status: string;
            risk_rating: string;
        };

        expect(inReviewPayload.case_id).toMatch(
            /^[0-9a-f-]{36}$/i,
        );
        expect(inReviewPayload.expected_revision).toBe(1);
        expect(inReviewPayload.status).toBe('in_review');
        expect(inReviewPayload.risk_rating).toBe('moderate');

        const dueDiligenceCaseId = inReviewPayload.case_id;

        await expect(
            partnerRow.getByText('in_review', {
                exact: true,
            }),
        ).toBeVisible();

        await page.reload();

        if ((await ddWorkflow.getAttribute('open')) === null) {
            await ddSummary.click();
        }

        await ddPartner.selectOption({
            label: PARTNER,
        });

        await expect(ddRevision).toHaveValue('2');
        await expect(ddStatus).toHaveValue('in_review');
        await expect(ddRisk).toHaveValue('moderate');
        await expect(ddIdentity).toHaveValue('Browser identity evidence');
        await expect(ddBackground).toHaveValue(
            'Browser background evidence',
        );

        await ddStatus.selectOption('completed');

        const completedRequestPromise = page.waitForRequest(
            (request) =>
                request.method() === 'PUT'
                && /\/partnership\/partners\/[^/]+\/due-diligence$/.test(
                    new URL(request.url()).pathname,
                ),
        );

        await ddSubmit.click();

        const completedRequest = await completedRequestPromise;
        const completedPayload = completedRequest.postDataJSON() as {
            case_id: string;
            expected_revision: number;
            status: string;
            risk_rating: string;
        };

        expect(completedPayload.case_id).toBe(dueDiligenceCaseId);
        expect(completedPayload.expected_revision).toBe(2);
        expect(completedPayload.status).toBe('completed');
        expect(completedPayload.risk_rating).toBe('moderate');

        await expect(
            partnerRow.getByText('completed', {
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            partnerRow.getByText('Risk: moderate', {
                exact: true,
            }),
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

        const contributionSummary = workflow
            .locator('summary')
            .filter({
                hasText: /^Add Contribution$/,
            });

        const contributionWorkflow = contributionSummary.locator('..');

        await expect(contributionWorkflow).toHaveAttribute('open', '');

        const contributionForm = contributionWorkflow.locator('form');
        const contributionSelects = contributionForm.locator('select');

        await expect(contributionSelects).toHaveCount(2);

        await contributionSelects.first().selectOption({
            label: PARTNER,
        });

        await contributionSelects.nth(1).selectOption('cash');

        await contributionWorkflow
            .getByLabel('Proposed value', {
                exact: true,
            })
            .fill('2500.00');

        await contributionWorkflow
            .getByLabel('Description', {
                exact: true,
            })
            .fill('Browser cash contribution');

        await contributionWorkflow
            .getByLabel('Amount committed', {
                exact: true,
            })
            .fill('2500.00');

        await contributionWorkflow
            .getByRole('button', {
                name: 'Add Contribution',
                exact: true,
            })
            .click();

        await page
            .getByRole('button', {
                name: 'Contribution Register',
                exact: true,
            })
            .click();

        await expect(
            page.getByText(
                'Browser cash contribution',
                { exact: true },
            ),
        ).toBeVisible();

        const scenarioSummary = workflow
            .locator('summary')
            .filter({
                hasText: /^Create Ownership Scenario$/,
            });

        const scenarioWorkflow = scenarioSummary.locator('..');

        if ((await scenarioWorkflow.getAttribute('open')) === null) {
            await scenarioSummary.click();
        }

        await expect(scenarioWorkflow).toHaveAttribute('open', '');

        await scenarioWorkflow
            .getByLabel('Scenario name', {
                exact: true,
            })
            .fill('Must Not Apply Scenario');

        await scenarioWorkflow
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

        await page
            .getByRole('button', {
                name: 'Ownership',
                exact: true,
            })
            .click();

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
