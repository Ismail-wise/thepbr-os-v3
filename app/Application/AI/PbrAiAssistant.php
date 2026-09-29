<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use InvalidArgumentException;
use Throwable;

final class PbrAiAssistant
{
    /** @var list<string> */
    private const array ALLOWED_CAPABILITIES = [
        'analyze',
        'explain',
        'compare',
        'summarize',
        'draft',
    ];

    /** @var list<string> */
    private const array PROHIBITED_ACTIONS = [
        'approve',
        'vote',
        'sign',
        'admit_partner',
        'change_ownership',
        'create_governance_authority',
        'change_governance_authority',
        'issue_payment',
        'revoke_business_rights',
        'archive_business',
        'close_business',
        'create_effective_record',
    ];

    public function __construct(
        private readonly BuildAuthorizedAiContext $context,
        private readonly PbrAiProvider $provider,
    ) {}

    /**
     * @return array{
     *   business:array{name:string},
     *   enabled:bool,
     *   advisory_only:bool
     * }|null
     */
    public function workspace(
        User $user,
        Business $business,
    ): ?array {
        if (! $this->context->canAccess($user, $business)) {
            return null;
        }

        return [
            'business' => [
                'name' => (string) $business->name,
            ],
            'enabled' => (
                (bool) config('pbr_ai.enabled', false)
                && $this->provider->available()
            ),
            'advisory_only' => true,
        ];
    }

    /**
     * @return array{
     *   status:string,
     *   answer:string,
     *   advisory_only:bool
     * }|null
     */
    public function ask(
        User $user,
        Business $business,
        string $prompt,
    ): ?array {
        $prompt = trim($prompt);
        $maxLength = max(
            1,
            (int) config('pbr_ai.max_prompt_length', 4000),
        );

        if ($prompt === '' || mb_strlen($prompt) > $maxLength) {
            throw new InvalidArgumentException(
                'PBR AI prompt is required and must remain within the configured length limit.',
            );
        }

        $context = $this->context->execute(
            $user,
            $business,
            $prompt,
        );

        if ($context === null) {
            return null;
        }

        if (
            ! (bool) config('pbr_ai.enabled', false)
            || ! $this->provider->available()
        ) {
            return $this->unavailable(
                (string) $context['language_mode'],
            );
        }

        try {
            $answer = trim($this->provider->respond([
                'prompt' => $prompt,
                'context' => $context,
                'constraints' => [
                    'advisory_only' => true,
                    'allowed_capabilities' => self::ALLOWED_CAPABILITIES,
                    'prohibited_actions' => self::PROHIBITED_ACTIONS,
                    'drafts_are_noncanonical' => true,
                    'drafts_require_normal_human_workflow' => true,
                    'user_entered_data_translation' => 'verbatim',
                    'no_unrestricted_database_access' => true,
                ],
            ]));
        } catch (Throwable) {
            return $this->unavailable(
                (string) $context['language_mode'],
            );
        }

        if ($answer === '') {
            return $this->unavailable(
                (string) $context['language_mode'],
            );
        }

        return [
            'status' => 'available',
            'answer' => $answer,
            'advisory_only' => true,
        ];
    }

    /**
     * @return array{
     *   status:string,
     *   answer:string,
     *   advisory_only:bool
     * }
     */
    private function unavailable(string $languageMode): array
    {
        $answer = match ($languageMode) {
            'my' => 'PBR AI ကို ဒီ environment မှာ မချိတ်ဆက်ရသေးပါ။',
            'mixed' => 'PBR AI is not configured in this environment · ဒီ environment မှာ မချိတ်ဆက်ရသေးပါ။',
            default => 'PBR AI is not configured in this environment.',
        };

        return [
            'status' => 'unavailable',
            'answer' => $answer,
            'advisory_only' => true,
        ];
    }
}
