<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use Tests\TestCase;

final class FormationHybridExperienceTest extends TestCase
{
    public function test_formation_page_exposes_the_guided_new_and_existing_business_journeys(): void
    {
        $source = file_get_contents(
            base_path('resources/js/pages/Formation/Index.vue'),
        );

        self::assertIsString($source);

        foreach ([
            'GuidedJourneyStepper',
            'BusinessModelGuidedJourney',
            'DemandEvidenceGuidedJourney',
            "v-show=\"active === 'bmc'\"",
            "stepDirection: 'Go / Revise / Hold / No-Go'",
            "stepPartnerSetup: 'Partner Setup'",
            "stepCurrentBmc: 'Current BMC'",
            "stepFinancial: 'Financial Baseline'",
            "stepAssets: 'Assets & Liabilities'",
            "stepValuation: 'Valuation'",
            "stepOwners: 'Existing Owners'",
            "stepObligationsRisks: 'Obligations & Risks'",
            "stepGap: 'PBR Gap'",
            "stepConversion: 'Conversion Plan'",
            "active === 'direction'",
            'formation-existing-profile',
            'formation-existing-financial',
            'formation-existing-assets',
            'formation-existing-valuation',
            'formation-existing-owners',
            'formation-existing-obligations_risks',
            'formation-existing-gap',
            'formation-existing-conversion',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }
    }

    public function test_business_model_redesign_uses_progressive_guidance_and_autosaved_canonical_sources(): void
    {
        $source = file_get_contents(
            base_path('resources/js/components/business-model/BusinessModelGuidedJourney.vue'),
        );

        self::assertIsString($source);

        foreach ([
            'useAutosaveDraft',
            "'/formation/bmc'",
            "'/formation/business-model/foundation'",
            "currentStep === 'purpose'",
            "currentStep === 'economics'",
            "currentStep === 'scalability'",
            "profileDraft.market.trim() !== ''",
            'competition_alternatives',
            "profileDraft.average_selling_price.trim() !== ''",
            "bmcDraft.key_resources.trim() !== ''",
            'Business Model guided journey',
            'Deep Feasibility',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }

        self::assertStringNotContainsString(
            'Exactly nine canonical blocks',
            $source,
        );
    }

    public function test_demand_evidence_redesign_reuses_existing_records_with_progressive_local_draft_preservation(): void
    {
        $source = file_get_contents(
            base_path('resources/js/components/business-model/DemandEvidenceGuidedJourney.vue'),
        );

        self::assertIsString($source);

        foreach ([
            "'/formation/new/assumptions'",
            "'/formation/new/validations'",
            "'/evidence'",
            'sessionStorage',
            'Demand evidence guided journey',
            "focus === 'assumption'",
            "focus === 'test'",
            "focus === 'evidence'",
            "focus === 'review'",
            '/records/documents',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }
    }

    public function test_existing_business_guided_sections_follow_the_conversion_journey_order(): void
    {
        $source = file_get_contents(
            base_path('resources/js/pages/Formation/Index.vue'),
        );

        self::assertIsString($source);

        $positions = array_map(
            static fn (string $id): int|false => strpos($source, $id),
            [
                'formation-existing-profile',
                'formation-existing-financial',
                'formation-existing-assets',
                'formation-existing-valuation',
                'formation-existing-owners',
                'formation-existing-obligations_risks',
                'formation-existing-gap',
                'formation-existing-conversion',
            ],
        );

        foreach ($positions as $position) {
            self::assertIsInt($position);
        }

        $numericPositions = array_values(
            array_filter(
                $positions,
                static fn (int|false $position): bool => is_int($position),
            ),
        );

        $sortedPositions = $numericPositions;
        sort($sortedPositions);

        self::assertSame($sortedPositions, $numericPositions);
    }

    public function test_guided_stepper_exposes_the_current_step_semantically(): void
    {
        $source = file_get_contents(
            base_path('resources/js/components/hybrid/GuidedJourneyStepper.vue'),
        );

        self::assertIsString($source);
        self::assertStringContainsString(':aria-current=', $source);
        self::assertStringContainsString(':data-state="step.state"', $source);
    }

    public function test_guided_stepper_explains_recorded_state_without_claiming_governance_approval(): void
    {
        $source = file_get_contents(
            base_path('resources/js/pages/Formation/Index.vue'),
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'A check means information has been recorded; it does not mean Governance approval.',
            $source,
        );
    }
}
