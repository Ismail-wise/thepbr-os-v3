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
    'F4 formation workspace preserves Guided Capital Steps 1-6 and New/Existing Business journeys',
    async ({ page }, testInfo) => {
        test.setTimeout(120_000);

        test.skip(
            testInfo.project.name !== 'chromium-desktop',
            'F4 deterministic journey runs only in desktop Chromium.',
        );

        await signIn(page);
        await switchBusiness(page, NEW_BUSINESS);

        // Closure proof: Master Journey exposes Deep Feasibility in the New
        // Business path without forcing historical Business Valuation.
        await page.goto('/overview');

        await page
            .getByText('View full Business journey', { exact: true })
            .click();

        const masterJourney = page.getByRole('navigation', {
            name: 'Master Business Journey',
            exact: true,
        });

        await expect(
            masterJourney.getByRole('button', {
                name: /^Business Model, Demand, Scalability & Break-even/,
            }),
        ).toBeVisible();

        await expect(
            masterJourney.getByRole('button', {
                name: /^Deep Feasibility/,
            }),
        ).toBeVisible();

        await expect(
            masterJourney.getByRole('button', {
                name: /^Business Valuation/,
            }),
        ).toHaveCount(0);

        await masterJourney
            .getByRole('button', {
                name: /^Deep Feasibility/,
            })
            .click();

        await expect(page).toHaveURL(
            /\/formation\?step=feasibility$/,
        );

        await expect(
            page.getByRole('heading', {
                name: 'Understand how ready this Business is to start',
                exact: true,
            }),
        ).toBeVisible();

        const deepFeasibilityJourney = page.getByRole('navigation', {
            name: 'Deep Feasibility guided journey',
            exact: true,
        });

        for (const step of [
            'Overview',
            'What PBR knows',
            'Feasibility areas',
            'What to work on',
            'Before GO can be evaluated',
            'Assessment history',
        ]) {
            await expect(
                deepFeasibilityJourney.getByRole('button', {
                    name: step,
                    exact: true,
                }),
            ).toBeVisible();
        }

        await deepFeasibilityJourney
            .getByRole('button', {
                name: 'Feasibility areas',
                exact: true,
            })
            .click();

        await expect(
            page.getByText('Assessed', { exact: true }),
        ).toHaveCount(4);

        await expect(
            page.getByText('Available later', { exact: true }),
        ).toHaveCount(5);

        await expect(
            page.getByRole('heading', {
                name: 'Strengths',
                exact: true,
            }),
        ).toBeVisible();

        await deepFeasibilityJourney
            .getByRole('button', {
                name: 'Before GO can be evaluated',
                exact: true,
            })
            .click();

        await expect(
            page.getByText(
                /does not guarantee business success or a GO result/i,
            ).first(),
        ).toBeVisible();

        await deepFeasibilityJourney
            .getByRole('button', {
                name: 'Assessment history',
                exact: true,
            })
            .click();

        await expect(
            page.getByText('Read-only historical snapshot', {
                exact: true,
            }),
        ).toHaveCount(1);

        const recordResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith(
                    '/formation/new/deep-feasibility/assessments',
                )
                && response.request().method() === 'POST',
        );

        await page
            .getByRole('button', {
                name: 'Record current assessment',
                exact: true,
            })
            .click();

        await recordResponse;

        await page
            .getByRole('navigation', {
                name: 'Deep Feasibility guided journey',
                exact: true,
            })
            .getByRole('button', {
                name: 'Assessment history',
                exact: true,
            })
            .click();

        await expect(
            page.getByText('Read-only historical snapshot', {
                exact: true,
            }),
        ).toHaveCount(2);

        // Leaving and returning through the existing Formation journey keeps
        // the accepted canonical data and immutable history intact.
        const closureSetupJourney = page.getByRole('navigation', {
            name: 'Guided setup journey',
            exact: true,
        });

        await closureSetupJourney
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

        await closureSetupJourney
            .getByRole('button', {
                name: /Feasibility/,
            })
            .click();

        await expect(
            page.getByRole('heading', {
                name: 'Understand how ready this Business is to start',
                exact: true,
            }),
        ).toBeVisible();

        // Deep Feasibility multilingual closure smoke: English, Myanmar and
        // Mixed all render through the same responsive guided component.
        const languageSwitcher = page.locator(
            '#shell-language-switcher',
        );

        await languageSwitcher.selectOption('my');

        await expect(
            page.getByRole('heading', {
                name: 'ဒီလုပ်ငန်းကို စဖို့ အခုဘယ်လောက်အဆင်သင့်ဖြစ်နေပြီလဲ',
                exact: true,
            }),
        ).toBeVisible();

        const guidedFeasibility = page.getByTestId(
            'deep-feasibility-guided-journey',
        );

        expect(
            await guidedFeasibility.evaluate(
                (element) =>
                    element.scrollWidth
                    <= element.clientWidth + 1,
            ),
        ).toBeTruthy();

        await languageSwitcher.selectOption('mixed');

        await expect(
            page.getByRole('heading', {
                name: 'ဒီ Business ကို အခု start လုပ်ဖို့ ဘယ်လောက် ready ဖြစ်နေပြီလဲ',
                exact: true,
            }),
        ).toBeVisible();

        await languageSwitcher.selectOption('en');

        await expect(
            page.getByRole('heading', {
                name: 'Understand how ready this Business is to start',
                exact: true,
            }),
        ).toBeVisible();

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

        await newBusinessJourney
            .getByRole('button', {
                name: /Validation/,
            })
            .click();

        await expect(
            page.getByRole('heading', {
                name: 'Test whether customers actually want this',
                exact: true,
            }),
        ).toBeVisible();

        const demandJourney = page.getByRole('navigation', {
            name: 'Demand evidence guided journey',
            exact: true,
        });

        for (const step of [
            'Assumption',
            'Validation test',
            'Evidence',
            'Review',
        ]) {
            await expect(
                demandJourney.getByRole('button', {
                    name: step,
                    exact: true,
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
            page.getByRole('heading', {
                name: 'Work out how much Capital this Business needs to start',
                exact: true,
            }),
        ).toBeVisible();

        const capitalPanel = page.getByTestId(
            'capital-guided-journey',
        );

        const capitalJourney = page.getByRole('navigation', {
            name: 'Capital guided calculate journey',
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

        const capitalComparison = page.getByTestId(
            'capital-plan-comparison',
        );

        await expect(
            capitalComparison.getByRole('heading', {
                name: 'Compare Lean, Base and Growth before Capital Approval',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            capitalComparison.getByTestId('comparison-prepare'),
        ).toBeDisabled();

        await expect(
            page.getByRole('button', {
                name: 'Promote to frozen Proposal',
                exact: true,
            }),
        ).toHaveCount(0);

        // New draft must be genuinely missing, not silently zeroed.
        await expect(
            capitalPanel.getByRole('radio', {
                name: 'Not entered yet',
                exact: true,
            }).first(),
        ).toBeChecked();

        await expect(
            capitalPanel
                .locator('dd')
                .filter({ hasText: 'Not available yet' })
                .first(),
        ).toBeVisible();

        // Step 1: explicitly confirm zero Startup Costs.
        await capitalPanel
            .getByRole('radio', {
                name: 'There are no Startup Costs / zero',
                exact: true,
            })
            .check();

        // Step 2: persist a real one-time Asset item.
        await capitalJourney
            .getByRole('button', {
                name: 'Initial Assets & Opening Inventory',
                exact: true,
            })
            .click();

        const assetsSection = page
            .getByRole('heading', {
                name: 'What assets or opening stock are needed?',
                exact: true,
            })
            .locator('..')
            .locator('..');

        await assetsSection
            .getByRole('radio', {
                name: 'I have items to enter',
                exact: true,
            })
            .check();

        await assetsSection
            .getByRole('button', {
                name: 'Add item',
                exact: true,
            })
            .click();

        await assetsSection
            .getByRole('combobox', {
                name: 'Category',
                exact: true,
            })
            .selectOption('equipment');

        await assetsSection
            .getByRole('textbox', {
                name: 'Description',
                exact: true,
            })
            .fill('Launch equipment');

        await assetsSection
            .getByRole('spinbutton', {
                name: 'Amount (USD)',
                exact: true,
            })
            .fill('2000.00');

        // Step 3: hidden method-specific fields disappear before save.
        await capitalJourney
            .getByRole('button', {
                name: 'Working Capital Forecast',
                exact: true,
            })
            .click();

        const workingMethod = capitalPanel.getByRole(
            'combobox',
            {
                name: 'Working Capital method',
                exact: true,
            },
        );

        await workingMethod.selectOption('monthly_burn');

        await capitalPanel
            .getByRole('spinbutton', {
                name: 'Monthly burn (USD)',
                exact: true,
            })
            .fill('999.00');

        await workingMethod.selectOption('fixed_amount');

        await expect(
            capitalPanel.getByRole('spinbutton', {
                name: 'Monthly burn (USD)',
                exact: true,
            }),
        ).toHaveCount(0);

        await capitalPanel
            .getByRole('spinbutton', {
                name: 'Fixed Working Capital amount (USD)',
                exact: true,
            })
            .fill('777.00');

        await workingMethod.selectOption(
            'canonical_operating_profile',
        );

        await expect(
            capitalPanel.getByRole('spinbutton', {
                name: 'Fixed Working Capital amount (USD)',
                exact: true,
            }),
        ).toHaveCount(0);

        await expect(
            capitalPanel.getByText(
                'Current Business Model assumptions available',
                { exact: true },
            ),
        ).toBeVisible();

        await capitalPanel
            .getByRole('spinbutton', {
                name: 'Working Capital months',
                exact: true,
            })
            .fill('3');

        // Step 4: contingency percentage remains server-calculated.
        await capitalJourney
            .getByRole('button', {
                name: 'Contingency Reserve',
                exact: true,
            })
            .click();

        await capitalPanel
            .getByRole('combobox', {
                name: 'Contingency method',
                exact: true,
            })
            .selectOption('percentage');

        await capitalPanel
            .getByRole('spinbutton', {
                name: 'Contingency percentage (%)',
                exact: true,
            })
            .fill('10');

        // Step 5: funding is Capital planning truth, not Contribution.
        await capitalJourney
            .getByRole('button', {
                name: 'Funding Position & Gap',
                exact: true,
            })
            .click();

        await capitalPanel
            .getByRole('spinbutton', {
                name: /^Confirmed Funding \(USD\)/,
            })
            .fill('1000.00');

        const capitalSaveResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith(
                    '/formation/capital/planning-draft',
                )
                && response.request().method() === 'PUT',
        );

        await capitalPanel
            .getByRole('button', {
                name: 'Save Capital draft',
                exact: true,
            })
            .click();

        await capitalSaveResponse;

        await expect(
            capitalPanel.getByText(
                'Capital draft saved. Server calculation refreshed.',
                { exact: true },
            ),
        ).toBeVisible();

        // Canonical Business Model operating cost = 6,200 × 3 months.
        // Assets 2,000 + WC 18,600 = 20,600; reserve 10% = 2,060.
        // Total = 22,660; funding = 1,000; gap = 21,660; funded = 4.41%.
        for (const value of [
            '22660.00 USD',
            '21660.00 USD',
            '4.41%',
        ]) {
            await expect(
                capitalPanel.getByText(value, {
                    exact: true,
                }).first(),
            ).toBeVisible();
        }

        // Step 6 consumes the server-derived calculation; it does not ask
        // the browser to re-enter or re-calculate Capital totals.
        await capitalJourney
            .getByRole('button', {
                name: 'Capital Rule & Allocation',
                exact: true,
            })
            .click();

        const ruleStep = page.getByTestId('capital-rule-step');

        await expect(
            page.getByRole('heading', {
                name: 'Set the Capital Rule & Allocation',
                exact: true,
            }),
        ).toBeVisible();

        for (const value of [
            '22660.00 USD',
            '1000.00 USD',
            '21660.00 USD',
            '4.41%',
        ]) {
            await expect(
                ruleStep.getByText(value, {
                    exact: true,
                }).first(),
            ).toBeVisible();
        }

        await expect(
            ruleStep.getByText(
                'A Funding Gap exists. Record at least one shortfall response before Step 6 is ready.',
                { exact: true },
            ),
        ).toBeVisible();

        const reduceScope = ruleStep.getByRole('checkbox', {
            name: 'Reduce the startup scope',
            exact: true,
        });
        const capitalCall = ruleStep.getByRole('checkbox', {
            name: 'Consider a Capital Call later',
            exact: true,
        });

        await reduceScope.check();
        await capitalCall.check();

        await expect(
            ruleStep.getByText('Priority 1', { exact: true }),
        ).toBeVisible();
        await expect(
            ruleStep.getByText('Priority 2', { exact: true }),
        ).toBeVisible();

        await ruleStep
            .getByRole('textbox', {
                name: 'Allocation notes',
                exact: true,
            })
            .fill('Protect the early operating buffer first.');

        await ruleStep
            .getByRole('textbox', {
                name: 'Shortfall rule notes',
                exact: true,
            })
            .fill('Review the shortfall before launch.');

        await ruleStep
            .getByRole('textbox', {
                name: 'Capital Call planning note',
                exact: true,
            })
            .fill(
                'Consider a future call only for the unresolved gap.',
            );

        await expect(
            ruleStep.getByText(
                /Actual contributor commitments, accepted contribution value, ownership and equity effects are handled later/i,
            ),
        ).toBeVisible();

        const capitalRuleResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith(
                    '/formation/capital/rule-draft',
                )
                && response.request().method() === 'PUT',
        );

        await ruleStep
            .getByRole('button', {
                name: 'Save Capital Rule',
                exact: true,
            })
            .click();

        await capitalRuleResponse;

        await expect(
            ruleStep.getByText(
                'Capital Rule draft saved.',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            ruleStep.getByText(
                'Current rule is recorded against the latest Capital numbers.',
                { exact: true },
            ),
        ).toBeVisible();

        // Capital Comparison starts from the current canonical Capital input
        // once, then lets the manager vary Lean / Base / Growth assumptions
        // without re-entering the complete plan or touching Step 6.
        const comparisonPrepareResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith(
                    '/formation/capital/comparison-draft/refresh',
                )
                && response.request().method() === 'POST',
        );

        await capitalComparison
            .getByTestId('comparison-prepare')
            .click();

        await comparisonPrepareResponse;

        const comparisonCards = capitalComparison.locator(
            '[data-testid^="comparison-card-"]',
        );

        await expect(comparisonCards).toHaveCount(3);
        await expect(comparisonCards.nth(0)).toHaveAttribute(
            'data-testid',
            'comparison-card-lean',
        );
        await expect(comparisonCards.nth(1)).toHaveAttribute(
            'data-testid',
            'comparison-card-base',
        );
        await expect(comparisonCards.nth(2)).toHaveAttribute(
            'data-testid',
            'comparison-card-growth',
        );

        const leanCard = capitalComparison.getByTestId(
            'comparison-card-lean',
        );
        const baseCard = capitalComparison.getByTestId(
            'comparison-card-base',
        );
        const growthCard = capitalComparison.getByTestId(
            'comparison-card-growth',
        );

        for (const card of [leanCard, baseCard, growthCard]) {
            await expect(
                card.getByText('22660.00 USD', { exact: true }),
            ).toBeVisible();
            await expect(
                card.getByText('21660.00 USD', { exact: true }),
            ).toBeVisible();
        }

        await leanCard
            .getByRole('spinbutton', {
                name: 'Working Capital months',
                exact: true,
            })
            .fill('2');

        await leanCard
            .getByRole('spinbutton', {
                name: 'Contingency %',
                exact: true,
            })
            .fill('5');

        await growthCard
            .getByRole('spinbutton', {
                name: 'Working Capital months',
                exact: true,
            })
            .fill('4');

        await growthCard
            .getByRole('spinbutton', {
                name: 'Contingency %',
                exact: true,
            })
            .fill('15');

        const comparisonSaveResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith(
                    '/formation/capital/comparison-draft',
                )
                && response.request().method() === 'PUT',
        );

        await capitalComparison
            .getByTestId('comparison-save')
            .click();

        await comparisonSaveResponse;

        for (const value of ['15120.00 USD', '14120.00 USD']) {
            await expect(
                leanCard.getByText(value, { exact: true }),
            ).toBeVisible();
        }

        for (const value of ['22660.00 USD', '21660.00 USD']) {
            await expect(
                baseCard.getByText(value, { exact: true }),
            ).toBeVisible();
        }

        for (const value of ['30820.00 USD', '29820.00 USD']) {
            await expect(
                growthCard.getByText(value, { exact: true }),
            ).toBeVisible();
        }

        await expect(
            capitalComparison.getByText(
                'Ready for comparison',
                { exact: true },
            ).first(),
        ).toBeVisible();

        await capitalComparison
            .getByTestId('comparison-preferred-base')
            .click();

        const preferredSaveResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith(
                    '/formation/capital/comparison-draft',
                )
                && response.request().method() === 'PUT',
        );

        await capitalComparison
            .getByTestId('comparison-save')
            .click();

        await preferredSaveResponse;

        await expect(
            capitalComparison.getByText(
                'Preferred planning candidate: Base',
                { exact: true },
            ),
        ).toBeVisible();

        // Capital Cycle 6: the exact Preferred Plan becomes a frozen governed
        // approval candidate. Review and governance use the existing V3
        // lifecycle; Approval remains separate from Signature and Effectivity.
        const capitalApproval = page.getByTestId(
            'capital-approval-stage',
        );

        await expect(
            capitalApproval.getByRole('heading', {
                name: 'Final Plan for Approval',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            capitalApproval.getByText('22660.00 USD', {
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            capitalApproval.getByText('21660.00 USD', {
                exact: true,
            }),
        ).toBeVisible();

        const approvalPrepareResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith(
                    '/formation/capital/approval/prepare',
                )
                && response.request().method() === 'POST',
        );

        await capitalApproval
            .getByTestId('capital-approval-prepare')
            .click();

        await approvalPrepareResponse;

        const reviewerSelect = capitalApproval.getByTestId(
            'capital-approval-reviewer',
        );

        await reviewerSelect.selectOption({
            label: 'F4 Browser Tester',
        });

        const reviewStartResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith(
                    '/formation/capital/approval/review',
                )
                && response.request().method() === 'POST',
        );

        await capitalApproval
            .getByTestId('capital-approval-start-review')
            .click();

        await reviewStartResponse;

        const reviewCompleteResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith(
                    '/formation/capital/approval/review',
                )
                && response.request().method() === 'PUT',
        );

        await capitalApproval
            .getByTestId('capital-approval-confirm-review')
            .click();

        await reviewCompleteResponse;

        await expect(
            capitalApproval.getByText(
                'Temporary Formation Authority',
                { exact: true },
            ),
        ).toBeVisible();

        const approvalOpenResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith(
                    '/formation/capital/approval/open',
                )
                && response.request().method() === 'POST',
        );

        await capitalApproval
            .getByTestId('capital-approval-open')
            .click();

        await approvalOpenResponse;

        const approvalEvidenceResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith(
                    '/formation/capital/approval/approve',
                )
                && response.request().method() === 'POST',
        );

        await capitalApproval
            .getByTestId('capital-approval-approve')
            .click();

        await approvalEvidenceResponse;

        const approvalResolveResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith(
                    '/formation/capital/approval/resolve',
                )
                && response.request().method() === 'POST',
        );

        await capitalApproval
            .getByTestId('capital-approval-resolve')
            .click();

        await approvalResolveResponse;

        await expect(
            capitalApproval.getByTestId(
                'capital-approval-approved',
            ),
        ).toBeVisible();

        await expect(
            capitalApproval.getByText(
                'Approved, but not Signed and not Effective.',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            capitalApproval.getByText(
                'F4 Browser Tester',
                { exact: true },
            ).last(),
        ).toBeVisible();

        // Capital Cycle 7: record the already-approved immutable Capital truth.
        // The user supplies only the new Record-stage facts; governed approval
        // evidence and Capital figures are displayed from the frozen snapshot.
        const capitalDecisionRecord = page.getByTestId(
            'capital-decision-record-stage',
        );

        await expect(
            capitalDecisionRecord.getByRole('heading', {
                name: 'Capital Decision Record',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            capitalDecisionRecord.getByText('22660.00 USD', {
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            capitalDecisionRecord.getByText('21660.00 USD', {
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            capitalDecisionRecord.getByText(
                'F4 Browser Tester',
                { exact: true },
            ).first(),
        ).toBeVisible();

        await capitalDecisionRecord
            .getByTestId('capital-decision-owner')
            .selectOption({ label: 'F4 Browser Tester' });

        await capitalDecisionRecord
            .getByTestId('capital-decision-effective-date')
            .fill('2027-01-15');

        await capitalDecisionRecord
            .getByTestId('capital-decision-review-date')
            .fill('2027-04-15');

        await capitalDecisionRecord
            .getByTestId('capital-decision-summary')
            .fill(
                'Approved Base Capital Plan for the planned opening.',
            );

        await capitalDecisionRecord
            .getByTestId('capital-decision-evidence')
            .fill(
                'Partner meeting note\nBank funding confirmation',
            );

        const decisionRecordResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith(
                    '/formation/capital/decision-record',
                )
                && response.request().method() === 'POST',
        );

        await capitalDecisionRecord
            .getByTestId('capital-decision-record-submit')
            .click();

        await decisionRecordResponse;

        await expect(
            capitalDecisionRecord.getByTestId(
                'capital-decision-record-summary',
            ),
        ).toBeVisible();

        await expect(
            capitalDecisionRecord.getByText(
                'Approved Base Capital Plan for the planned opening.',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            capitalDecisionRecord.getByText(
                '• Partner meeting note',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            capitalDecisionRecord.getByText(
                '• Bank funding confirmation',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            capitalDecisionRecord.getByText(
                'Approved — Awaiting Effectivity',
                { exact: true },
            ).first(),
        ).toBeVisible();

        await expect(
            capitalDecisionRecord.getByText(
                'Recorded does not mean Signed, Effective or Action Complete.',
                { exact: true },
            ),
        ).toBeVisible();

        // Governed Approval + Decision Record still do not make Capital
        // current/effective or complete the ACT stage in Master Journey.
        // Governed Approval does not make Capital current/effective in the
        // Master Business Journey.
        await page.goto('/overview');

        await page
            .getByText('View full Business journey', { exact: true })
            .click();

        const postApprovalJourney = page.getByRole('navigation', {
            name: 'Master Business Journey',
            exact: true,
        });

        const capitalJourneyStep = postApprovalJourney.getByRole(
            'button',
            {
                name: /^Capital/,
            },
        );

        await expect(capitalJourneyStep).toBeVisible();
        await expect(capitalJourneyStep).not.toContainText(
            'Information already recorded',
        );

        // Saved draft survives normal reload. Explicit zero remains distinct
        // from the untouched/missing state and hidden stale method values stay
        // out of the canonical draft.
        await page.goto('/formation?step=capital');

        const reloadedCapitalPanel = page.getByTestId(
            'capital-guided-journey',
        );
        const reloadedCapitalJourney = page.getByRole(
            'navigation',
            {
                name: 'Capital guided calculate journey',
                exact: true,
            },
        );
        const reloadedComparison = page.getByTestId(
            'capital-plan-comparison',
        );
        const reloadedApproval = page.getByTestId(
            'capital-approval-stage',
        );
        const reloadedDecisionRecord = page.getByTestId(
            'capital-decision-record-stage',
        );

        await expect(
            reloadedComparison.getByText(
                'Preferred planning candidate: Base',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            reloadedDecisionRecord.getByText(
                'Approved Base Capital Plan for the planned opening.',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            reloadedDecisionRecord.getByText(
                '• Partner meeting note',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            reloadedDecisionRecord.getByText(
                '2027-01-15',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            reloadedDecisionRecord.getByText(
                '2027-04-15',
                { exact: true },
            ),
        ).toBeVisible();

        await reloadedCapitalJourney
            .getByRole('button', {
                name: 'Capital Rule & Allocation',
                exact: true,
            })
            .click();

        const reloadedRuleStep = page.getByTestId(
            'capital-rule-step',
        );

        await expect(
            reloadedRuleStep.getByRole('checkbox', {
                name: 'Reduce the startup scope',
                exact: true,
            }),
        ).toBeChecked();

        await expect(
            reloadedRuleStep.getByRole('checkbox', {
                name: 'Consider a Capital Call later',
                exact: true,
            }),
        ).toBeChecked();

        await expect(
            reloadedRuleStep.getByRole('textbox', {
                name: 'Capital Call planning note',
                exact: true,
            }),
        ).toHaveValue(
            'Consider a future call only for the unresolved gap.',
        );

        // If Steps 1-5 change, the saved Step 6 rule becomes needs-review
        // rather than silently remaining current.
        await reloadedCapitalJourney
            .getByRole('button', {
                name: 'Funding Position & Gap',
                exact: true,
            })
            .click();

        await reloadedCapitalPanel
            .getByRole('spinbutton', {
                name: /^Confirmed Funding \(USD\)/,
            })
            .first()
            .fill('2000.00');

        const capitalRevisionResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith(
                    '/formation/capital/planning-draft',
                )
                && response.request().method() === 'PUT',
        );

        await reloadedCapitalPanel
            .getByRole('button', {
                name: 'Save Capital draft',
                exact: true,
            })
            .click();

        await capitalRevisionResponse;

        await expect(
            reloadedComparison.getByText(
                'The current Capital plan changed after this comparison was prepared. Refresh before treating any scenario as ready for the next stage.',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            reloadedComparison.getByText(
                'Needs review',
                { exact: true },
            ).first(),
        ).toBeVisible();

        await expect(
            reloadedComparison.getByTestId(
                'comparison-preferred-base',
            ),
        ).toBeDisabled();

        await expect(
            reloadedDecisionRecord.getByTestId(
                'capital-decision-record-stale-warning',
            ),
        ).toBeVisible();

        await expect(
            reloadedDecisionRecord.getByText(
                '22660.00 USD',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            reloadedDecisionRecord.getByText(
                '21660.00 USD',
                { exact: true },
            ),
        ).toBeVisible();

        await reloadedCapitalJourney
            .getByRole('button', {
                name: 'Capital Rule & Allocation',
                exact: true,
            })
            .click();

        await expect(
            reloadedRuleStep.getByText(
                'Review this rule again',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            reloadedRuleStep.getByText(
                '20660.00 USD',
                { exact: true },
            ).first(),
        ).toBeVisible();

        await reloadedCapitalJourney
            .getByRole('button', {
                name: 'Startup Cost Plan',
                exact: true,
            })
            .click();

        await expect(
            reloadedCapitalPanel.getByRole('radio', {
                name: 'There are no Startup Costs / zero',
                exact: true,
            }),
        ).toBeChecked();

        await reloadedCapitalJourney
            .getByRole('button', {
                name: 'Initial Assets & Opening Inventory',
                exact: true,
            })
            .click();

        await expect(
            reloadedCapitalPanel.getByRole('textbox', {
                name: 'Description',
                exact: true,
            }),
        ).toHaveValue('Launch equipment');

        await reloadedCapitalJourney
            .getByRole('button', {
                name: 'Working Capital Forecast',
                exact: true,
            })
            .click();

        await expect(
            reloadedCapitalPanel.getByRole('combobox', {
                name: 'Working Capital method',
                exact: true,
            }),
        ).toHaveValue('canonical_operating_profile');

        await expect(
            reloadedCapitalPanel.getByText(
                'Resolved monthly operating cost: 6200.00 USD',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            reloadedCapitalPanel.getByRole('spinbutton', {
                name: 'Monthly burn (USD)',
                exact: true,
            }),
        ).toHaveCount(0);

        await reloadedCapitalJourney
            .getByRole('button', {
                name: 'Capital Rule & Allocation',
                exact: true,
            })
            .click();

        // English / Myanmar / Mixed responsive smoke.
        const capitalLanguageSwitcher = page.locator(
            '#shell-language-switcher',
        );

        await capitalLanguageSwitcher.selectOption('my');

        // Wait for the language-setting navigation to finish before moving
        // from its default Step 1 focus back into Step 6.
        await expect(
            page.getByRole('heading', {
                name: 'ဒီလုပ်ငန်းစဖို့ Capital ဘယ်လောက်လိုမလဲ အဆင့်လိုက်တွက်ပါ',
                exact: true,
            }),
        ).toBeVisible();

        await reloadedCapitalJourney
            .getByRole('button', {
                name: 'Capital Rule & Allocation',
                exact: true,
            })
            .click();

        await expect(
            page.getByRole('heading', {
                name: 'Capital Rule & Allocation ကို သတ်မှတ်ပါ',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            reloadedComparison.getByRole('heading', {
                name: 'Capital Approval မတိုင်မီ Lean, Base, Growth ကို နှိုင်းယှဉ်ပါ',
                exact: true,
            }),
        ).toBeVisible();

        expect(
            await reloadedComparison.evaluate(
                (element) =>
                    element.scrollWidth
                    <= element.clientWidth + 1,
            ),
        ).toBeTruthy();

        await expect(
            reloadedApproval.getByRole('heading', {
                name: 'Approval အတွက် နောက်ဆုံး Capital Plan',
                exact: true,
            }),
        ).toBeVisible();

        expect(
            await reloadedApproval.evaluate(
                (element) =>
                    element.scrollWidth
                    <= element.clientWidth + 1,
            ),
        ).toBeTruthy();

        await expect(
            reloadedDecisionRecord.getByRole('heading', {
                name: 'Capital Decision Record',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            reloadedDecisionRecord.getByText(
                'ဒီ Approval ပြီးနောက် Capital planning ပြောင်းထားပါတယ်။ ဒီ Record က အတည်ပြုခဲ့တဲ့ version ကို မှတ်တမ်းတင်တာဖြစ်ပြီး ပြောင်းထားတဲ့ plan အတွက် Approval အသစ်လိုပါတယ်။',
                { exact: true },
            ),
        ).toBeVisible();

        expect(
            await reloadedDecisionRecord.evaluate(
                (element) =>
                    element.scrollWidth
                    <= element.clientWidth + 1,
            ),
        ).toBeTruthy();

        expect(
            await reloadedCapitalPanel.evaluate(
                (element) =>
                    element.scrollWidth
                    <= element.clientWidth + 1,
            ),
        ).toBeTruthy();

        await capitalLanguageSwitcher.selectOption('mixed');

        await expect(
            page.getByRole('heading', {
                name: 'ဒီ Business စဖို့ Capital ဘယ်လောက်လိုမလဲ guided steps နဲ့တွက်ပါ',
                exact: true,
            }),
        ).toBeVisible();

        await reloadedCapitalJourney
            .getByRole('button', {
                name: 'Capital Rule & Allocation',
                exact: true,
            })
            .click();

        await expect(
            page.getByRole('heading', {
                name: 'Capital Rule & Allocation ကို set လုပ်ပါ',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            reloadedComparison.getByRole('heading', {
                name: 'Capital Approval မတိုင်မီ Lean / Base / Growth ကို compare လုပ်ပါ',
                exact: true,
            }),
        ).toBeVisible();

        expect(
            await reloadedComparison.evaluate(
                (element) =>
                    element.scrollWidth
                    <= element.clientWidth + 1,
            ),
        ).toBeTruthy();

        await expect(
            reloadedApproval.getByRole('heading', {
                name: 'Final Plan for Approval',
                exact: true,
            }),
        ).toBeVisible();

        expect(
            await reloadedApproval.evaluate(
                (element) =>
                    element.scrollWidth
                    <= element.clientWidth + 1,
            ),
        ).toBeTruthy();

        await expect(
            reloadedDecisionRecord.getByRole('heading', {
                name: 'Capital Decision Record',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            reloadedDecisionRecord.getByText(
                'Capital planning changed after this approval. ဒီ Record က approved historical version ကိုပြတာဖြစ်ပြီး changed plan အတွက် new approval လိုပါတယ်။',
                { exact: true },
            ),
        ).toBeVisible();

        expect(
            await reloadedDecisionRecord.evaluate(
                (element) =>
                    element.scrollWidth
                    <= element.clientWidth + 1,
            ),
        ).toBeTruthy();

        await capitalLanguageSwitcher.selectOption('en');

        await expect(
            page.getByRole('heading', {
                name: 'Work out how much Capital this Business needs to start',
                exact: true,
            }),
        ).toBeVisible();

        await reloadedCapitalJourney
            .getByRole('button', {
                name: 'Capital Rule & Allocation',
                exact: true,
            })
            .click();

        await expect(
            page.getByRole('heading', {
                name: 'Set the Capital Rule & Allocation',
                exact: true,
            }),
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
            page.getByRole('heading', {
                name: 'Estimate a practical value range for the existing Business',
                exact: true,
            }),
        ).toBeVisible();

        const valuationJourney = page.getByRole('navigation', {
            name: 'Business Valuation guided journey',
            exact: true,
        });

        for (const step of [
            'Business facts',
            'Historical facts',
            'Assumptions',
            'Review',
            'Result',
        ]) {
            await expect(
                valuationJourney.getByRole('button', {
                    name: new RegExp(step),
                }),
            ).toBeVisible();
        }

        await expect(
            page.getByText('250,000 USD', {
                exact: true,
            }).first(),
        ).toBeVisible();

        await expect(
            page.getByText('Asset-Based', {
                exact: true,
            }).first(),
        ).toBeVisible();

        await expect(
            page.getByText(
                /not a guaranteed market value.*Contribution Valuation/i,
            ).first(),
        ).toBeVisible();

        await page
            .getByRole('button', {
                name: 'Prepare another estimate',
                exact: true,
            })
            .click();

        await valuationJourney
            .getByRole('button', {
                name: /Historical facts/,
            })
            .click();

        const ebitdaToggle = page.getByRole('checkbox', {
            name: /I have a usable EBITDA figure/,
        });

        await expect(
            page.getByLabel('Historical EBITDA', { exact: true }),
        ).toHaveCount(0);

        await ebitdaToggle.check();

        const ebitdaInput = page.getByLabel('Historical EBITDA', {
            exact: true,
        });

        await expect(ebitdaInput).toBeVisible();
        await ebitdaInput.fill('123456.00');

        await page.reload();

        const reloadedValuationJourney = page.getByRole('navigation', {
            name: 'Business Valuation guided journey',
            exact: true,
        });

        await reloadedValuationJourney
            .getByRole('button', {
                name: /Historical facts/,
            })
            .click();

        await expect(
            page.getByRole('checkbox', {
                name: /I have a usable EBITDA figure/,
            }),
        ).toBeChecked();

        await expect(
            page.getByLabel('Historical EBITDA', { exact: true }),
        ).toHaveValue('123456.00');
    },
);
