<?php

declare(strict_types=1);

namespace Tests\Feature\Redesign;

use Tests\TestCase;

final class PhaseOneUxFoundationTest extends TestCase
{
    public function test_guided_journey_stepper_remains_semantic_touch_friendly_and_language_safe(): void
    {
        $source = $this->source(
            'resources/js/components/hybrid/GuidedJourneyStepper.vue',
        );

        foreach ([
            ':aria-current=',
            ':aria-disabled=',
            ':data-state="step.state"',
            'min-w-0',
            'break-words',
            'disabled:cursor-not-allowed',
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }
    }

    public function test_standard_text_field_uses_label_instruction_and_example_placeholder_layers(): void
    {
        $field = $this->source(
            'resources/js/components/ui/PbrField.vue',
        );
        $input = $this->source(
            'resources/js/components/ui/PbrTextInput.vue',
        );

        foreach ([
            'instruction?: string',
            'example?: string',
            ':for="forId"',
            ':id="descriptionId"',
        ] as $contract) {
            $this->assertStringContainsString($contract, $field);
        }

        foreach ([
            '<PbrField',
            ':instruction="instruction"',
            ':example="example"',
            ':placeholder="examplePlaceholder"',
            ':aria-describedby="descriptionId"',
            ':aria-invalid=',
        ] as $contract) {
            $this->assertStringContainsString($contract, $input);
        }
    }

    public function test_progressive_reveal_removes_hidden_controls_without_mutating_draft_data(): void
    {
        $component = $this->source(
            'resources/js/components/hybrid/ProgressiveReveal.vue',
        );
        $payload = $this->source(
            'resources/js/support/progressiveForm.ts',
        );

        $this->assertStringContainsString('v-if="visible"', $component);
        $this->assertStringContainsString(
            'visibleProgressivePayload',
            $payload,
        );
        $this->assertStringContainsString(
            'visibility[key] === false',
            $payload,
        );
        $this->assertStringContainsString(
            'The original source object is never mutated',
            $payload,
        );
    }

    public function test_choice_or_custom_accepts_reusable_presets_without_blocking_free_text(): void
    {
        $source = $this->source(
            'resources/js/components/ui/ChoiceOrCustom.vue',
        );

        foreach ([
            "customLabel: '+ Add your own'",
            'customOpen',
            'customDraft',
            "emit('update:modelValue', value)",
            'break-words',
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }

        $this->assertStringNotContainsString(
            'text-transform: uppercase',
            $source,
        );
    }

    private function source(string $path): string
    {
        $source = file_get_contents(base_path($path));

        $this->assertIsString(
            $source,
            sprintf('Expected source file to be readable: %s', $path),
        );

        return $source;
    }
}
