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
    'F4 formation workspace distinguishes New and Existing Business journeys and preserves scenario-only Capital',
    async ({ page }, testInfo) => {
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

        const capitalJourney = page.getByRole('navigation', {
            name: 'Capital Planning Workflow',
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

        await expect(
            page.getByRole('heading', {
                name: 'Live Capital Position',
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page.getByText('38.46%', { exact: true }),
        ).toBeVisible();

        await expect(
            page.getByText('6500.00', { exact: true }),
        ).toBeVisible();

        await expect(
            page.getByText('4000.00', { exact: true }),
        ).toBeVisible();

        await expect(
            page.getByText(
                'Scenario is planning only and never changes live truth.',
                { exact: true },
            ),
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
