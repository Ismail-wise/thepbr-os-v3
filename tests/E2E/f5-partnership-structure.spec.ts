import { expect, test, type Locator, type Page } from '@playwright/test';

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

const openPartnership = async (page: Page) => {
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
            name: 'Contribution Register',
            exact: true,
        })
        .click();

    await expect(
        page.locator(
            '[data-pbr-contribution-guided-journey]',
        ),
    ).toBeVisible();
};

const selectContributionStep = async (
    page: Page,
    name: string,
) => {
    const journey = page.getByRole('navigation', {
        name: 'Partner Contribution Journey',
        exact: true,
    });

    await journey
        .getByRole('button', {
            name,
            exact: true,
        })
        .click();
};

const approveCurrentProposal = async (
    page: Page,
    decisionType: 'contribution_approval' | 'contribution_acceptance' | 'ownership_approval',
) => {
    await expect(page).toHaveURL(/\/governance$/);

    const startReview = page.getByRole('button', {
        name: 'Start my review',
        exact: true,
    });

    await expect(startReview).toHaveCount(1);

    const startReviewResponse = page.waitForResponse(
        (response) =>
            /\/governance\/proposal-versions\/[^/]+\/reviews$/.test(
                new URL(response.url()).pathname,
            )
            && response.request().method() === 'POST',
    );

    await startReview.click();
    await startReviewResponse;

    const approveReview = page.getByRole('button', {
        name: 'Approve review',
        exact: true,
    });

    await expect(approveReview).toHaveCount(1);

    const approveReviewResponse = page.waitForResponse(
        (response) =>
            /\/governance\/proposal-reviews\/[^/]+\/complete$/.test(
                new URL(response.url()).pathname,
            )
            && response.request().method() === 'POST',
    );

    await approveReview.click();
    await approveReviewResponse;

    const openDecision = page.getByRole('button', {
        name: 'Open decision',
        exact: true,
    });

    await expect(openDecision).toHaveCount(1);

    const openRow = openDecision.locator(
        'xpath=ancestor::tr',
    );
    const decisionTypeSelect = openRow
        .locator('select')
        .first();

    await decisionTypeSelect.selectOption(decisionType);

    const openDecisionResponse = page.waitForResponse(
        (response) =>
            /\/governance\/proposal-versions\/[^/]+\/decisions$/.test(
                new URL(response.url()).pathname,
            )
            && response.request().method() === 'POST',
    );

    await openDecision.click();
    await openDecisionResponse;

    const approve = page.getByRole('button', {
        name: 'Approve',
        exact: true,
    });

    await expect(approve).toHaveCount(1);

    const approvalResponse = page.waitForResponse(
        (response) =>
            /\/governance\/decisions\/[^/]+\/approvals$/.test(
                new URL(response.url()).pathname,
            )
            && response.request().method() === 'POST',
    );

    await approve.click();
    await approvalResponse;

    const resolve = page.getByRole('button', {
        name: 'Resolve decision',
        exact: true,
    });

    await expect(resolve).toHaveCount(1);

    const resolveResponse = page.waitForResponse(
        (response) =>
            /\/governance\/decisions\/[^/]+\/resolve$/.test(
                new URL(response.url()).pathname,
            )
            && response.request().method() === 'POST',
    );

    await resolve.click();
    await resolveResponse;

    await expect(
        page.getByText(decisionType, {
            exact: true,
        }).last(),
    ).toBeVisible();

};

const completeContributionContentReview = async (
    stage: Locator,
) => {
    const start = stage.getByRole('button', {
        name: 'Start content review',
        exact: true,
    });

    await expect(start).toHaveCount(1);
    await start.click();

    const approve = stage.getByRole('button', {
        name: 'Approve content',
        exact: true,
    });

    await expect(approve).toHaveCount(1);
    await approve.click();
};

test(
    'F5 Partner Contributions completes the governed Chapter 2 journey without creating Ownership',
    async ({ page }, testInfo) => {
        test.skip(
            testInfo.project.name !== 'chromium-desktop',
            'F5 deterministic Chapter 2 journey runs only in desktop Chromium.',
        );

        test.setTimeout(180_000);

        await signIn(page);
        await switchBusiness(page, BUSINESS);
        await openPartnership(page);

        await expect(
            page.getByRole('heading', {
                name: 'Partner Contributions',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page.getByText(
                'Contribution is not Equity, Shares or Ownership. Only governed Accepted Contribution Value may feed later Ownership planning.',
                { exact: true },
            ),
        ).toBeVisible();

        const setup = page.locator(
            '[data-contribution-step="setup"]',
        );

        await expect(setup).toBeVisible();

        await setup
            .getByLabel('Valuation date', {
                exact: true,
            })
            .fill('2026-10-06');

        await setup
            .getByLabel('Currency', {
                exact: true,
            })
            .fill('USD');

        await setup
            .getByLabel('Period start', {
                exact: true,
            })
            .fill('2026-01-01');

        await setup
            .getByLabel('Period end', {
                exact: true,
            })
            .fill('2026-12-31');

        await setup
            .locator('select')
            .first()
            .selectOption({
                index: 1,
            });

        await setup
            .locator('input[type="checkbox"]')
            .first()
            .check();

        const setupResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith(
                    '/partnership/contributions/setup',
                )
                && response.request().method() === 'PUT',
        );

        await setup
            .getByRole('button', {
                name: 'Save Contribution Setup',
                exact: true,
            })
            .click();

        await setupResponse;

        await selectContributionStep(
            page,
            'Partners',
        );

        const partners = page.locator(
            '[data-contribution-step="partners"]',
        );

        await expect(
            partners.getByText(PARTNER, {
                exact: true,
            }),
        ).toBeVisible();

        await selectContributionStep(
            page,
            'Valuation',
        );

        const valuation = page.locator(
            '[data-contribution-step="valuation"]',
        );

        const contributionSelect = valuation
            .locator('select')
            .first();

        await contributionSelect.selectOption({
            index: 1,
        });

        const reviewedValue = valuation.getByLabel(
            'Reviewed Value',
            {
                exact: true,
            },
        );

        await expect(reviewedValue).toHaveValue(
            '5000.00',
        );

        const valuationMethod = valuation
            .locator('select')
            .nth(1);

        await expect(valuationMethod).toContainText(
            'Bank / receipt evidence',
        );

        await reviewedValue.fill('4900.00');

        await valuationMethod.selectOption(
            'bank_or_receipt_evidence',
        );

        await expect(reviewedValue).toHaveValue(
            '4900.00',
        );

        await expect(valuationMethod).toHaveValue(
            'bank_or_receipt_evidence',
        );

        await expect.poll(
            async () => valuation
                .locator('form')
                .evaluate(
                    (form) => (
                        form as HTMLFormElement
                    ).checkValidity(),
                ),
        ).toBe(true);

        await Promise.all([
            page.waitForResponse(
                (response) =>
                    /\/partnership\/contributions\/[^/]+\/review$/.test(
                        new URL(response.url()).pathname,
                    )
                    && response.request().method() === 'PUT',
            ),
            valuation
                .getByRole('button', {
                    name: 'Record Review',
                    exact: true,
                })
                .click(),
        ]);

        await page
            .getByRole('navigation', {
                name: 'Workspace navigation',
            })
            .getByRole('link', {
                name: 'Document Vault',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(
            /\/records\/documents$/,
        );

        const evidenceDocumentRow = page
            .getByRole('row')
            .filter({
                has: page.getByText(
                    'F5 Contribution Evidence',
                    { exact: true },
                ),
            });

        await evidenceDocumentRow
            .getByRole('link', {
                name: 'Open',
                exact: true,
            })
            .click();

        const targetType = page.getByRole(
            'combobox',
            {
                name: 'Target type',
                exact: true,
            },
        );

        await targetType.selectOption(
            'contribution',
        );

        const targetRecord = page.getByRole(
            'combobox',
            {
                name: 'Target record',
                exact: true,
            },
        );

        await targetRecord.selectOption({
            label:
                'Prepared cash contribution · Prepared Partner · cash · reviewed',
        });

        const linkEvidenceResponse =
            page.waitForResponse(
                (response) =>
                    /\/records\/evidence\/[^/]+\/links$/.test(
                        new URL(
                            response.url(),
                        ).pathname,
                    )
                    && response.request().method()
                        === 'POST',
            );

        await page
            .getByRole('button', {
                name: 'Link',
                exact: true,
            })
            .click();

        await linkEvidenceResponse;

        await openPartnership(page);

        await selectContributionStep(
            page,
            'Evidence & Conditions',
        );

        const evidenceStage = page.locator(
            '[data-contribution-step="evidence"]',
        );

        await expect(
            evidenceStage.getByText(
                /Evidence:\s*1 · Verified:\s*0/,
            ),
        ).toBeVisible();

        await selectContributionStep(
            page,
            'Approval',
        );

        let approvalStage = page.locator(
            '[data-contribution-step="approval"]',
        );

        const approvalSelect =
            approvalStage.locator('select');
        const approvalOptions =
            approvalSelect.locator(
                'option:not([disabled])',
            );

        await expect(
            approvalOptions,
        ).toHaveCount(1);

        const approvalCandidate =
            approvalOptions.first();
        const approvalCandidateValue =
            await approvalCandidate.getAttribute(
                'value',
            );
        const approvalCandidateLabel = (
            await approvalCandidate.textContent()
            ?? ''
        )
            .replace(/\s+/g, ' ')
            .trim();

        expect(
            approvalCandidateLabel,
        ).toContain(PARTNER);
        expect(
            approvalCandidateLabel,
        ).toContain(
            'Prepared cash contribution',
        );
        expect(
            approvalCandidateLabel,
        ).toContain('USD');

        const approvalValueMatch =
            approvalCandidateLabel.match(
                /·\s*([0-9]+(?:\.[0-9]+)?)\s+USD$/,
            );

        expect(
            approvalValueMatch,
        ).not.toBeNull();
        expect(
            Number(
                approvalValueMatch?.[1],
            ),
        ).toBe(4900);

        if (!approvalCandidateValue) {
            throw new Error(
                'Reviewed Approval candidate is missing its stable Contribution identity.',
            );
        }

        await approvalSelect.selectOption(
            approvalCandidateValue,
        );

        const prepareApprovalResponse =
            page.waitForResponse(
                (response) =>
                    /\/partnership\/contributions\/[^/]+\/governance$/.test(
                        new URL(
                            response.url(),
                        ).pathname,
                    )
                    && response.request().method()
                        === 'POST',
            );

        await approvalStage
            .getByRole('button', {
                name: 'Prepare Approval',
                exact: true,
            })
            .click();

        await prepareApprovalResponse;

        approvalStage = page.locator(
            '[data-contribution-step="approval"]',
        );

        await completeContributionContentReview(
            approvalStage,
        );

        await approvalStage
            .getByRole('link', {
                name: 'Open Governance',
                exact: true,
            })
            .click();

        await approveCurrentProposal(
            page,
            'contribution_approval',
        );

        await openPartnership(page);

        await selectContributionStep(
            page,
            'Approval',
        );

        approvalStage = page.locator(
            '[data-contribution-step="approval"]',
        );

        const syncApprovalResponse =
            page.waitForResponse(
                (response) =>
                    /\/partnership\/contribution-submissions\/[^/]+\/sync-decision$/.test(
                        new URL(
                            response.url(),
                        ).pathname,
                    )
                    && response.request().method()
                        === 'POST',
            );

        await approvalStage
            .getByRole('button', {
                name: 'Sync governed decision',
                exact: true,
            })
            .click();

        await syncApprovalResponse;

        await selectContributionStep(
            page,
            'Delivery',
        );

        const delivery = page.locator(
            '[data-contribution-step="delivery"]',
        );

        await delivery
            .locator('select')
            .first()
            .selectOption({
                index: 1,
            });

        await expect(
            delivery.getByLabel(
                'Delivered Value',
                { exact: true },
            ),
        ).toHaveValue('4900.00');

        const deliveryResponse =
            page.waitForResponse(
                (response) =>
                    /\/partnership\/contributions\/[^/]+\/delivery$/.test(
                        new URL(
                            response.url(),
                        ).pathname,
                    )
                    && response.request().method()
                        === 'POST',
            );

        await delivery
            .getByRole('button', {
                name: 'Record Delivery',
                exact: true,
            })
            .click();

        await deliveryResponse;

        const contributionJourney = page.getByRole(
            'navigation',
            {
                name: 'Partner Contribution Journey',
                exact: true,
            },
        );

        await expect(
            contributionJourney.getByRole(
                'button',
                {
                    name: 'Acceptance',
                    exact: true,
                },
            ),
        ).toHaveAttribute(
            'aria-current',
            'step',
        );

        await selectContributionStep(
            page,
            'Acceptance',
        );

        let acceptanceStage = page.locator(
            '[data-contribution-step="acceptance"]',
        );

        await expect(
            acceptanceStage,
        ).toBeVisible();

        await acceptanceStage
            .locator('select')
            .first()
            .selectOption({
                index: 1,
            });

        await acceptanceStage
            .locator('input[inputmode="decimal"]')
            .fill('4800.00');

        const prepareAcceptanceResponse =
            page.waitForResponse(
                (response) =>
                    /\/partnership\/contributions\/[^/]+\/governance$/.test(
                        new URL(
                            response.url(),
                        ).pathname,
                    )
                    && response.request().method()
                        === 'POST',
            );

        await acceptanceStage
            .getByRole('button', {
                name: 'Prepare Acceptance',
                exact: true,
            })
            .click();

        await prepareAcceptanceResponse;

        acceptanceStage = page.locator(
            '[data-contribution-step="acceptance"]',
        );

        await completeContributionContentReview(
            acceptanceStage,
        );

        await acceptanceStage
            .getByRole('link', {
                name: 'Open Governance',
                exact: true,
            })
            .click();

        await approveCurrentProposal(
            page,
            'contribution_acceptance',
        );

        await openPartnership(page);

        await selectContributionStep(
            page,
            'Acceptance',
        );

        acceptanceStage = page.locator(
            '[data-contribution-step="acceptance"]',
        );

        const syncAcceptanceResponse =
            page.waitForResponse(
                (response) =>
                    /\/partnership\/contribution-submissions\/[^/]+\/sync-decision$/.test(
                        new URL(
                            response.url(),
                        ).pathname,
                    )
                    && response.request().method()
                        === 'POST',
            );

        await acceptanceStage
            .getByRole('button', {
                name: 'Sync governed decision',
                exact: true,
            })
            .click();

        await syncAcceptanceResponse;

        await expect(
            page.getByText(
                'Next: Decision Record',
                { exact: true },
            ),
        ).toBeVisible();

        await selectContributionStep(
            page,
            'Matrix & Register',
        );

        const register = page.locator(
            '[data-contribution-step="register"]',
        );

        await expect(
            register,
        ).toBeVisible();

        await expect(
            register.getByText(
                '4800.00 USD',
                { exact: true },
            ).first(),
        ).toBeVisible();

        await expect(
            register.getByText(
                'Accepted',
                { exact: true },
            ).last(),
        ).toBeVisible();

        await selectContributionStep(
            page,
            'Decision Record',
        );

        const decision = page.locator(
            '[data-contribution-step="decision-record"]',
        );

        await decision
            .locator('select')
            .first()
            .selectOption({
                index: 1,
            });

        await decision
            .getByLabel('Effective Date', {
                exact: true,
            })
            .fill('2026-10-07');

        await decision
            .getByLabel('Review Date', {
                exact: true,
            })
            .fill('2027-04-07');

        await decision
            .getByLabel('Decision Summary', {
                exact: true,
            })
            .fill(
                'Governed Accepted Contribution Register recorded for the current Partner Contribution decision.',
            );

        const decisionResponse =
            page.waitForResponse(
                (response) =>
                    response.url().endsWith(
                        '/partnership/contributions/decision-record',
                    )
                    && response.request().method()
                        === 'POST',
            );

        await decision
            .getByRole('button', {
                name: 'Record Contribution Decision',
                exact: true,
            })
            .click();

        await decisionResponse;

        await expect(
            page.getByText(
                'Chapter 2 complete',
                { exact: true },
            ),
        ).toBeVisible();

        await selectContributionStep(
            page,
            'Action Plan',
        );

        const actionPlan = page.locator(
            '[data-contribution-step="action-plan"]',
        );

        await expect(
            actionPlan.getByText(
                'No Action is required. The chapter can still be complete once the current Decision is recorded.',
                { exact: true },
            ),
        ).toBeVisible();

        await actionPlan
            .locator('select')
            .first()
            .selectOption({
                index: 1,
            });

        const reviewSuggestion = actionPlan.locator(
            '[data-contribution-suggestion="review_contribution_decision"]',
        );

        await expect(
            reviewSuggestion,
        ).toBeVisible();

        const actionResponse =
            page.waitForResponse(
                (response) =>
                    response.url().endsWith(
                        '/partnership/contributions/actions/suggested',
                    )
                    && response.request().method()
                        === 'POST',
            );

        await reviewSuggestion
            .getByRole('button', {
                name: 'Add Action',
                exact: true,
            })
            .click();

        await actionResponse;

        const actionCard = actionPlan
            .locator(
                '[data-contribution-action-id]',
            )
            .filter({
                hasText:
                    'Review current Partner Contribution decision',
            })
            .first();

        await expect(
            actionCard,
        ).toBeVisible();

        await actionCard
            .locator('select')
            .selectOption('completed');

        const updateActionResponse =
            page.waitForResponse(
                (response) =>
                    /\/partnership\/contributions\/actions\/[^/]+$/.test(
                        new URL(
                            response.url(),
                        ).pathname,
                    )
                    && response.request().method()
                        === 'PUT',
            );

        await actionCard
            .getByRole('button', {
                name: 'Update',
                exact: true,
            })
            .click();

        await updateActionResponse;

        await expect(
            actionPlan.getByText(
                'completed',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            actionPlan.getByText(
                'Accepted Contributions can now be used as input to later Ownership planning. This chapter itself creates no shares or ownership.',
                { exact: true },
            ),
        ).toBeVisible();

        await actionPlan
            .getByRole('link', {
                name: 'Continue to Ownership',
                exact: true,
            })
            .click();

        await expect(
            page.locator(
                '[data-pbr-ownership-equity-guided-journey]',
            ),
        ).toBeVisible();

        const ownershipJourney = page.getByRole(
            'navigation',
            {
                name: 'Ownership & Equity journey',
                exact: true,
            },
        );

        await ownershipJourney
            .getByRole('button', {
                name: 'Accepted Contributions',
                exact: true,
            })
            .click();

        const acceptedStage = page.locator(
            '[data-ownership-step="accepted_contributions"]',
        );

        await expect(
            acceptedStage.getByText(
                '4800.00 USD',
                { exact: true },
            ),
        ).toBeVisible();

        await ownershipJourney
            .getByRole('button', {
                name: 'Share Value',
                exact: true,
            })
            .click();

        const shareValueStage = page.locator(
            '[data-ownership-step="share_value"]',
        );

        await shareValueStage
            .getByLabel('Scenario name', {
                exact: true,
            })
            .fill('F5 Governed Ownership');

        await shareValueStage
            .getByLabel('Share Value', {
                exact: true,
            })
            .fill('100.00');

        await shareValueStage
            .getByLabel('Authorized Shares', {
                exact: true,
            })
            .fill('100');

        await shareValueStage
            .getByLabel('Reserved / Unissued', {
                exact: true,
            })
            .fill('10');

        const createOwnershipResponse =
            page.waitForResponse(
                (response) =>
                    response.url().endsWith(
                        '/partnership/ownership/chapter/scenarios',
                    )
                    && response.request().method()
                        === 'POST',
            );

        await shareValueStage
            .getByRole('button', {
                name: 'Create Share Allocation',
                exact: true,
            })
            .click();

        await createOwnershipResponse;

        const refreshedOwnershipJourney =
            page.getByRole(
                'navigation',
                {
                    name: 'Ownership & Equity journey',
                    exact: true,
                },
            );

        await refreshedOwnershipJourney
            .getByRole('button', {
                name: 'Share Allocation',
                exact: true,
            })
            .click();

        const allocationStage = page.locator(
            '[data-ownership-step="allocation"]',
        );

        await expect(
            allocationStage.getByText(
                '48.00000000',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            allocationStage.getByText(
                '100.0000%',
                { exact: true },
            ),
        ).toBeVisible();

        await refreshedOwnershipJourney
            .getByRole('button', {
                name: 'Share Classes & Rights',
                exact: true,
            })
            .click();

        const rightsStage = page.locator(
            '[data-ownership-step="share_classes_rights"]',
        );

        await rightsStage
            .getByLabel('Voting Right per Share', {
                exact: true,
            })
            .fill('1');

        await rightsStage
            .getByLabel('Profit Right per Share', {
                exact: true,
            })
            .fill('0.75');

        await rightsStage
            .getByLabel('Transfer allowed', {
                exact: true,
            })
            .check();

        await rightsStage
            .getByLabel('Restrictions', {
                exact: true,
            })
            .fill('Transfers require the agreed process.');

        await rightsStage
            .getByLabel('Special Rights', {
                exact: true,
            })
            .fill('No additional Governance authority is created.');

        const rightsResponse = page.waitForResponse(
            (response) =>
                /\/partnership\/ownership\/chapter\/scenarios\/[^/]+\/share-classes\/[^/]+$/.test(
                    new URL(response.url()).pathname,
                )
                && response.request().method() === 'PUT',
        );

        await rightsStage
            .getByRole('button', {
                name: 'Save Rights Review',
                exact: true,
            })
            .click();

        await rightsResponse;

        await page
            .getByRole('navigation', {
                name: 'Ownership & Equity journey',
                exact: true,
            })
            .getByRole('button', {
                name: 'Vesting',
                exact: true,
            })
            .click();

        const vestingStage = page.locator(
            '[data-ownership-step="vesting"]',
        );

        await vestingStage
            .getByLabel(
                'No — these shares are fully vested',
                { exact: true },
            )
            .check();

        const vestingResponse = page.waitForResponse(
            (response) =>
                /\/partnership\/ownership\/chapter\/scenarios\/[^/]+\/positions\/[^/]+\/vesting$/.test(
                    new URL(response.url()).pathname,
                )
                && response.request().method() === 'PUT',
        );

        await vestingStage
            .getByRole('button', {
                name: 'Save Vesting Decision',
                exact: true,
            })
            .click();

        await vestingResponse;

        await page
            .getByRole('navigation', {
                name: 'Ownership & Equity journey',
                exact: true,
            })
            .getByRole('button', {
                name: 'Voting & Profit Rights',
                exact: true,
            })
            .click();

        const votingProfitStage = page.locator(
            '[data-ownership-step="voting_profit_rights"]',
        );

        await expect(
            votingProfitStage.getByText(
                '48.00000000',
                { exact: true },
            ).first(),
        ).toBeVisible();

        await expect(
            votingProfitStage.getByText(
                '36.00000000',
                { exact: true },
            ),
        ).toBeVisible();

        await votingProfitStage
            .getByRole('button', {
                name: 'Continue',
                exact: true,
            })
            .click();

        const capacityStage = page.locator(
            '[data-ownership-step="share_capacity"]',
        );

        const capacityResponse = page.waitForResponse(
            (response) =>
                /\/partnership\/ownership\/chapter\/scenarios\/[^/]+\/capacity$/.test(
                    new URL(response.url()).pathname,
                )
                && response.request().method() === 'PUT',
        );

        await capacityStage
            .getByRole('button', {
                name: 'Save Capacity Review',
                exact: true,
            })
            .click();

        await capacityResponse;

        await page
            .getByRole('navigation', {
                name: 'Ownership & Equity journey',
                exact: true,
            })
            .getByRole('button', {
                name: 'New Share Rule',
                exact: true,
            })
            .click();

        const issuanceStage = page.locator(
            '[data-ownership-step="new_share_rule"]',
        );

        await issuanceStage
            .getByLabel(
                'Who should approve new shares?',
                { exact: true },
            )
            .fill(
                'Existing Partners through the agreed Governance process.',
            );

        await issuanceStage
            .getByLabel('Approval threshold (%)', {
                exact: true,
            })
            .fill('75');

        await issuanceStage
            .getByLabel(
                'Existing Partners get the agreed first opportunity',
                { exact: true },
            )
            .check();

        await issuanceStage
            .getByLabel('New Share valuation method', {
                exact: true,
            })
            .fill(
                'Independent agreed valuation at the time of issuance.',
            );

        await issuanceStage
            .getByLabel(
                /future new shares may reduce existing Partners/,
            )
            .check();

        const issuanceResponse = page.waitForResponse(
            (response) =>
                /\/partnership\/ownership\/chapter\/scenarios\/[^/]+\/issuance-rule$/.test(
                    new URL(response.url()).pathname,
                )
                && response.request().method() === 'PUT',
        );

        await issuanceStage
            .getByRole('button', {
                name: 'Save New Share Rule',
                exact: true,
            })
            .click();

        await issuanceResponse;

        await expect(
            issuanceStage.getByRole('button', {
                name: 'Continue',
                exact: true,
            }),
        ).toBeVisible();

        await page
            .getByRole('navigation', {
                name: 'Ownership & Equity journey',
                exact: true,
            })
            .getByRole('button', {
                name: 'Review & Approve',
                exact: true,
            })
            .click();

        let ownershipApprovalStage = page.locator(
            '[data-ownership-step="review_approve"]',
        );

        const freezeResponse = page.waitForResponse(
            (response) =>
                /\/partnership\/ownership\/scenarios\/[^/]+\/freeze$/.test(
                    new URL(response.url()).pathname,
                )
                && response.request().method() === 'POST',
        );

        await ownershipApprovalStage
            .getByRole('button', {
                name: 'Freeze for Review',
                exact: true,
            })
            .click();

        await freezeResponse;

        ownershipApprovalStage = page.locator(
            '[data-ownership-step="review_approve"]',
        );

        await ownershipApprovalStage
            .getByLabel('Effective Date', {
                exact: true,
            })
            .fill('2026-10-07');

        const submitOwnershipResponse =
            page.waitForResponse(
                (response) =>
                    /\/partnership\/ownership\/scenarios\/[^/]+\/governance$/.test(
                        new URL(response.url()).pathname,
                    )
                    && response.request().method()
                        === 'POST',
            );

        await ownershipApprovalStage
            .getByRole('button', {
                name: 'Submit for Approval',
                exact: true,
            })
            .click();

        await submitOwnershipResponse;

        ownershipApprovalStage = page.locator(
            '[data-ownership-step="review_approve"]',
        );

        const startOwnershipReviewResponse =
            page.waitForResponse(
                (response) =>
                    /\/partnership\/ownership-submissions\/[^/]+\/content-review$/.test(
                        new URL(response.url()).pathname,
                    )
                    && response.request().method()
                        === 'POST',
            );

        await ownershipApprovalStage
            .getByRole('button', {
                name: 'Start Ownership Review',
                exact: true,
            })
            .click();

        await startOwnershipReviewResponse;

        ownershipApprovalStage = page.locator(
            '[data-ownership-step="review_approve"]',
        );

        const confirmOwnershipReviewResponse =
            page.waitForResponse(
                (response) =>
                    /\/partnership\/ownership-submissions\/[^/]+\/content-review$/.test(
                        new URL(response.url()).pathname,
                    )
                    && response.request().method()
                        === 'POST',
            );

        await ownershipApprovalStage
            .getByRole('button', {
                name: 'Confirm Ownership Review',
                exact: true,
            })
            .click();

        await confirmOwnershipReviewResponse;

        ownershipApprovalStage = page.locator(
            '[data-ownership-step="review_approve"]',
        );

        await ownershipApprovalStage
            .getByRole('link', {
                name: 'Open Governance Review',
                exact: true,
            })
            .click();

        await approveCurrentProposal(
            page,
            'ownership_approval',
        );

        await page.goto(
            '/partnership?section=ownership',
        );

        await expect(
            page.locator(
                '[data-pbr-ownership-equity-guided-journey]',
            ),
        ).toBeVisible();

        await page
            .getByRole('navigation', {
                name: 'Ownership & Equity journey',
                exact: true,
            })
            .getByRole('button', {
                name: 'Review & Approve',
                exact: true,
            })
            .click();

        ownershipApprovalStage = page.locator(
            '[data-ownership-step="review_approve"]',
        );

        await expect(
            ownershipApprovalStage
                .getByText(
                    'Approved — Ready for Effect',
                    { exact: true },
                )
                .first(),
        ).toBeVisible();

        const effectOwnershipResponse =
            page.waitForResponse(
                (response) =>
                    /\/partnership\/ownership-submissions\/[^/]+\/effect$/.test(
                        new URL(response.url()).pathname,
                    )
                    && response.request().method()
                        === 'POST',
            );

        await ownershipApprovalStage
            .getByRole('button', {
                name: 'Make Current when Eligible',
                exact: true,
            })
            .click();

        await effectOwnershipResponse;

        await page
            .getByRole('navigation', {
                name: 'Ownership & Equity journey',
                exact: true,
            })
            .getByRole('button', {
                name: 'Share Register',
                exact: true,
            })
            .click();

        const ownershipRegister = page.locator(
            '[data-ownership-step="share_register"]',
        );

        await expect(
            ownershipRegister.getByText(
                'Current / Effective',
                { exact: true },
            ),
        ).toBeVisible();

        await expect(
            ownershipRegister.getByText(
                '48.00000000',
                { exact: true },
            ).first(),
        ).toBeVisible();

        await page
            .getByRole('navigation', {
                name: 'Ownership & Equity journey',
                exact: true,
            })
            .getByRole('button', {
                name: 'Decision Record',
                exact: true,
            })
            .click();

        const ownershipDecision = page.locator(
            '[data-ownership-step="decision_record"]',
        );

        const ownershipDecisionOwner =
            ownershipDecision.getByRole(
                'combobox',
            );

        await expect(
            ownershipDecisionOwner,
        ).toBeVisible();

        await expect(
            ownershipDecisionOwner,
        ).toHaveValue(/.+/);

        await ownershipDecision
            .getByRole('textbox', {
                name: 'Review Date',
                exact: true,
            })
            .fill('2027-10-07');

        await ownershipDecision
            .getByRole('textbox', {
                name: 'Decision Summary',
                exact: true,
            })
            .fill(
                'Current governed Ownership and Equity terms recorded for F5.',
            );

        const ownershipDecisionResponse =
            page.waitForResponse(
                (response) =>
                    response.url().endsWith(
                        '/partnership/ownership/chapter/decision-record',
                    )
                    && response.request().method()
                        === 'POST',
            );

        await ownershipDecision
            .getByRole('button', {
                name: 'Save Ownership Decision Record',
                exact: true,
            })
            .click();

        await ownershipDecisionResponse;

        await page
            .getByRole('navigation', {
                name: 'Ownership & Equity journey',
                exact: true,
            })
            .getByRole('button', {
                name: 'Action Plan',
                exact: true,
            })
            .click();

        const ownershipActions = page.locator(
            '[data-ownership-step="action_plan"]',
        );

        await expect(
            ownershipActions.getByText(
                'No Ownership Actions are required right now. This does not block Chapter completion.',
                { exact: true },
            ),
        ).toBeVisible();

        await ownershipActions
            .getByRole('button', {
                name: 'Continue',
                exact: true,
            })
            .click();

        const ownershipContinue = page.locator(
            '[data-ownership-step="continue_governance"]',
        );

        await expect(
            ownershipContinue.getByRole('link', {
                name: 'Continue to Governance / Decision Rules',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            ownershipContinue.getByRole('link', {
                name: 'Open Equity Scenario Simulator',
                exact: true,
            }),
        ).toBeVisible();
    },
);
