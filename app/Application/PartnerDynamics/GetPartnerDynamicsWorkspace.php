<?php

declare(strict_types=1);

namespace App\Application\PartnerDynamics;

use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\PartnerDynamics\PartnerDynamicsAssessment;
use Illuminate\Support\Facades\DB;

final class GetPartnerDynamicsWorkspace
{
    public function __construct(
        private readonly PartnerDynamicsAlignmentService $alignment,
    ) {}

    /**
     * Read-only Partner Dynamics workspace projection.
     *
     * Raw questionnaire answers never leave the personal assessment boundary.
     *
     * @return array<string,mixed>
     */
    public function execute(User $user, Business $business): array
    {
        $businessId = (string) $business->getKey();
        $version = (string) config('partner_dynamics.version', 'v1');

        $linkedPartners = DB::table('partner_membership_links as link')
            ->join('partners as partner', function ($join): void {
                $join
                    ->on('partner.id', '=', 'link.partner_id')
                    ->on('partner.business_id', '=', 'link.business_id');
            })
            ->join('memberships as membership', function ($join): void {
                $join
                    ->on('membership.id', '=', 'link.membership_id')
                    ->on('membership.business_id', '=', 'link.business_id');
            })
            ->join('users as account', 'account.id', '=', 'membership.user_id')
            ->leftJoin('user_profiles as profile', 'profile.user_id', '=', 'account.id')
            ->where('link.business_id', $businessId)
            ->where('membership.access_status', 'active')
            ->orderBy('partner.display_name')
            ->get([
                'partner.id as partner_id',
                'partner.display_name',
                'partner.status as partner_status',
                'membership.id as membership_id',
                'membership.user_id',
                'profile.display_name as account_display_name',
            ]);

        $participants = [];
        $alignmentParticipants = [];
        $completed = 0;

        foreach ($linkedPartners as $row) {
            $assessment = PartnerDynamicsAssessment::query()
                ->where('user_id', (string) $row->user_id)
                ->where('assessment_version', $version)
                ->where('status', 'completed')
                ->orderByDesc('completed_at')
                ->orderByDesc('created_at')
                ->first();

            $displayName = trim((string) ($row->display_name ?? ''));
            $accountDisplayName = trim(
                (string) ($row->account_display_name ?? ''),
            );

            if ($displayName === '') {
                $displayName = $accountDisplayName !== ''
                    ? $accountDisplayName
                    : 'Partner';
            }

            $isCurrentUser = (string) $row->user_id
                === (string) $user->getKey();

            if ($assessment === null) {
                $participants[] = [
                    'partnerId' => (string) $row->partner_id,
                    'displayName' => $displayName,
                    'partnerStatus' => (string) $row->partner_status,
                    'completionStatus' => 'pending',
                    'primaryProfile' => null,
                    'secondaryProfile' => null,
                    'completedAt' => null,
                    'isCurrentUser' => $isCurrentUser,
                ];

                continue;
            }

            $completed++;

            $participants[] = [
                'partnerId' => (string) $row->partner_id,
                'displayName' => $displayName,
                'partnerStatus' => (string) $row->partner_status,
                'completionStatus' => 'completed',
                'primaryProfile' => (string) $assessment->primary_profile,
                'secondaryProfile' => $assessment->secondary_profile === null
                    ? null
                    : (string) $assessment->secondary_profile,
                'completedAt' => $assessment->completed_at?->toIso8601String(),
                'isCurrentUser' => $isCurrentUser,
            ];

            $alignmentParticipants[] = [
                'user_id' => (string) $row->user_id,
                'name' => $displayName,
                'primary_profile' => (string) $assessment->primary_profile,
                'secondary_profile' => $assessment->secondary_profile === null
                    ? null
                    : (string) $assessment->secondary_profile,
                'dimension_scores' => $assessment->dimension_scores ?? [],
            ];
        }

        $total = count($participants);
        $currentUserParticipant = collect($participants)
            ->firstWhere('isCurrentUser', true);

        $alignment = count($alignmentParticipants) >= 2
            ? $this->sanitizeAlignment(
                $this->alignment->analyze($alignmentParticipants),
            )
            : null;

        return [
            'progress' => [
                'completed' => $completed,
                'total' => $total,
                'ready' => $alignment !== null,
            ],
            'currentUser' => [
                'linked' => $currentUserParticipant !== null,
                'completed' => ($currentUserParticipant['completionStatus'] ?? null)
                    === 'completed',
                'assessmentRoute' => '/partner-dynamics',
            ],
            'participants' => $participants,
            'alignment' => $alignment,
            'advisoryOnly' => true,
        ];
    }

    /**
     * Remove individual numeric working-style scores and internal identifiers
     * before sharing workspace-level alignment with partners.
     *
     * @param  array<string,mixed>  $alignment
     * @return array<string,mixed>
     */
    private function sanitizeAlignment(array $alignment): array
    {
        $summary = $alignment['alignment_summary'] ?? [];

        return [
            'summary' => [
                'participantCount' => (int) ($summary['participant_count'] ?? 0),
                'sharedStrengthCount' => (int) ($summary['shared_strength_count'] ?? 0),
                'complementaryAreaCount' => (int) ($summary['complementary_area_count'] ?? 0),
                'importantDifferenceCount' => (int) ($summary['important_difference_count'] ?? 0),
                'sharedBlindSpotCount' => (int) ($summary['shared_blind_spot_count'] ?? 0),
                'note' => (string) ($summary['note'] ?? ''),
            ],
            'sharedStrengths' => collect($alignment['shared_strengths'] ?? [])
                ->map(
                    static fn (array $row): array => [
                        'dimension' => (string) ($row['dimension'] ?? ''),
                        'label' => (string) ($row['label'] ?? ''),
                        'averageScore' => (float) ($row['average_score'] ?? 0),
                        'message' => (string) ($row['message'] ?? ''),
                    ],
                )
                ->values()
                ->all(),
            'complementaryAreas' => collect($alignment['complementary_areas'] ?? [])
                ->map(
                    static fn (array $row): array => [
                        'dimension' => (string) ($row['dimension'] ?? ''),
                        'label' => (string) ($row['label'] ?? ''),
                        'gap' => (float) ($row['gap'] ?? 0),
                        'message' => (string) ($row['message'] ?? ''),
                    ],
                )
                ->values()
                ->all(),
            'importantDifferences' => collect($alignment['important_differences'] ?? [])
                ->map(
                    static fn (array $row): array => [
                        'dimension' => (string) ($row['dimension'] ?? ''),
                        'label' => (string) ($row['label'] ?? ''),
                        'gap' => (float) ($row['gap'] ?? 0),
                        'message' => (string) ($row['message'] ?? ''),
                    ],
                )
                ->values()
                ->all(),
            'sharedBlindSpots' => collect($alignment['shared_blind_spots'] ?? [])
                ->map(
                    static fn (array $row): array => [
                        'dimension' => (string) ($row['dimension'] ?? ''),
                        'label' => (string) ($row['label'] ?? ''),
                        'averageScore' => (float) ($row['average_score'] ?? 0),
                        'message' => (string) ($row['message'] ?? ''),
                    ],
                )
                ->values()
                ->all(),
            'roleSuggestions' => collect($alignment['role_suggestions'] ?? [])
                ->map(
                    static fn (array $row): array => [
                        'name' => (string) ($row['name'] ?? ''),
                        'primaryProfile' => (string) ($row['primary_profile'] ?? ''),
                        'secondaryProfile' => $row['secondary_profile'] ?? null,
                        'suggestions' => array_values($row['suggestions'] ?? []),
                        'note' => (string) ($row['note'] ?? ''),
                    ],
                )
                ->values()
                ->all(),
            'decisionRecommendations' => array_values(
                $alignment['decision_recommendations'] ?? [],
            ),
            'discussionPriorities' => array_values(
                $alignment['discussion_priorities'] ?? [],
            ),
        ];
    }
}
