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
        page.getByRole('heading', {
            name: 'Account Home',
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

        await expect(
            page.getByText('Partner Foundation', { exact: true }),
        ).toBeVisible();

        await expect(
            page.getByText(
                'Reference only. It never creates ownership, authority or workspace access.',
                { exact: true },
            ),
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

        const ddForm = ddWorkflow.locator('form');
        const ddSelects = ddForm.locator('select');
        const ddTextareas = ddForm.locator('textarea');
        const ddPartner = ddSelects.nth(0);
        const ddStatus = ddSelects.nth(1);
        const ddRisk = ddSelects.nth(2);
        const ddRevision = ddForm.locator('input[type="number"]');
        const ddIdentity = ddTextareas.nth(0);
        const ddBackground = ddTextareas.nth(1);
        const ddSubmit = ddForm.getByRole('button', {
            name: 'Due Diligence',
            exact: true,
        });

        await expect(ddWorkflow).toHaveAttribute('open', '');
        await expect(ddSelects).toHaveCount(3);
        await expect(ddTextareas).toHaveCount(2);

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

        await expect(
            page.getByRole('columnheader', {
                name: 'Reviewed',
                exact: true,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('columnheader', {
                name: 'Approved',
                exact: true,
            }),
        ).toBeVisible();
        await expect(
            page.getByText('Accepted Contribution Matrix', {
                exact: true,
            }),
        ).toBeVisible();

        const reviewSummary = workflow
            .locator('summary')
            .filter({
                hasText: /^Review Contribution$/,
            });
        const reviewWorkflow = reviewSummary.locator('..');

        if ((await reviewWorkflow.getAttribute('open')) === null) {
            await reviewSummary.click();
        }

        const reviewContribution = reviewWorkflow.getByLabel(
            'Contribution',
        );
        const reviewRevision = reviewWorkflow.getByLabel(
            'Revision',
            { exact: true },
        );
        const reviewedValue = reviewWorkflow.getByLabel(
            'Reviewed value',
            { exact: true },
        );
        const valuationMethod = reviewWorkflow.getByLabel(
            'Valuation method',
            { exact: true },
        );
        const reviewButton = reviewWorkflow.getByRole('button', {
            name: 'Review Contribution',
            exact: true,
        });

        await reviewContribution.selectOption({
            label: 'Prepared cash contribution · proposed',
        });

        await expect(reviewRevision).toHaveValue('1');
        await expect(reviewRevision).toHaveAttribute('readonly', '');
        await expect(
            reviewWorkflow.getByText(/Current status:\s*proposed/i),
        ).toBeVisible();

        await reviewedValue.fill('4900.00');
        await valuationMethod.fill('Browser review basis');

        const reviewRequestPromise = page.waitForRequest(
            (request) =>
                request.method() === 'PUT'
                && /\/partnership\/contributions\/[^/]+\/review$/.test(
                    new URL(request.url()).pathname,
                ),
        );

        await reviewButton.click();

        const reviewRequest = await reviewRequestPromise;
        const reviewPayload = reviewRequest.postDataJSON() as {
            expected_revision: number;
            reviewed_value: string;
            valuation_method: string;
        };

        expect(reviewPayload.expected_revision).toBe(1);
        expect(reviewPayload.reviewed_value).toBe('4900.00');
        expect(reviewPayload.valuation_method).toBe(
            'Browser review basis',
        );

        await expect(reviewRevision).toHaveValue('2');
        await expect(reviewedValue).toHaveValue('4900.00');
        await expect(valuationMethod).toHaveValue(
            'Browser review basis',
        );
        await expect(
            reviewWorkflow.getByText(/Current status:\s*reviewed/i),
        ).toBeVisible();
        await expect(reviewButton).toBeDisabled();

        await page
            .getByRole('navigation', {
                name: 'Workspace navigation',
            })
            .getByRole('link', {
                name: 'Document Vault',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/records\/documents$/);

        const evidenceDocumentRow = page
            .getByRole('row')
            .filter({
                has: page.getByText('F5 Contribution Evidence', {
                    exact: true,
                }),
            });

        await evidenceDocumentRow
            .getByRole('link', {
                name: 'Open',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/records\/documents\//);

        const targetType = page.getByRole('combobox', {
            name: 'Target type',
            exact: true,
        });

        await targetType.selectOption('contribution');

        const targetRecord = page.getByRole('combobox', {
            name: 'Target record',
            exact: true,
        });

        await expect(targetRecord).toHaveJSProperty(
            'tagName',
            'SELECT',
        );

        await targetRecord.selectOption({
            label: 'Prepared cash contribution · Prepared Partner · cash · reviewed',
        });

        const evidenceLinkRequestPromise = page.waitForRequest(
            (request) =>
                request.method() === 'POST'
                && /\/records\/evidence\/[^/]+\/links$/.test(
                    new URL(request.url()).pathname,
                ),
        );

        await page
            .getByRole('button', {
                name: 'Link',
                exact: true,
            })
            .click();

        const evidenceLinkRequest = await evidenceLinkRequestPromise;
        const evidenceLinkPayload = evidenceLinkRequest.postDataJSON() as {
            target_type: string;
            target_id: string;
        };

        expect(evidenceLinkPayload.target_type).toBe('contribution');
        expect(evidenceLinkPayload.target_id).toMatch(
            /^[0-9a-f-]{36}$/i,
        );

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
            page.getByText('Governed Ownership', { exact: true }),
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
