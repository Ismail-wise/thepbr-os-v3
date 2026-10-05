<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;

final class GetCapitalPlanningDraftCalculation
{
    public function __construct(
        private readonly GetCapitalPlanningDraft $drafts,
        private readonly CalculateCapitalFoundation $calculator,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
    ): ?array {
        $draft = $this->drafts->execute($user, $business);

        if ($draft === null) {
            return null;
        }

        if ($draft['revision'] === 0 || $draft['input'] === null) {
            return [
                'draftContractVersion' => $draft['contractVersion'],
                'revision' => 0,
                'calculation' => null,
                'reasonCode' => 'capital_planning_draft_not_started',
            ];
        }

        $calculation = $this->calculator->execute(
            $user,
            $business,
            $draft['input'],
        );

        if ($calculation === null) {
            return null;
        }

        return [
            'draftContractVersion' => $draft['contractVersion'],
            'revision' => $draft['revision'],
            'calculation' => $calculation,
            'reasonCode' => null,
        ];
    }
}
