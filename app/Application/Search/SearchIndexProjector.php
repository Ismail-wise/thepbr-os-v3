<?php

declare(strict_types=1);

namespace App\Application\Search;

use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SearchIndexProjector
{
    /** @var list<string> */
    private const array SOURCE_TYPES = [
        'formal_record_version',
        'document',
        'partner',
        'contribution',
        'ownership_register_version',
        'partner_change_case',
        'exit_case',
        'closure_case',
        'governance_decision',
        'finance_payment',
        'distribution_run',
        'risk_item',
        'risk_protection',
        'risk_incident',
        'risk_control_test',
        'continuity_test',
        'continuity_emergency_access_activation',
        'conflict_case',
    ];

    public function rebuildBusiness(Business $business): int
    {
        $businessId = (string) $business->getKey();
        $entries = $this->entries($businessId);

        DB::transaction(function () use ($businessId, $entries): void {
            DB::table('search_index_entries')
                ->where('business_id', $businessId)
                ->whereIn('source_type', self::SOURCE_TYPES)
                ->delete();

            foreach (array_chunk($entries, 250) as $chunk) {
                DB::table('search_index_entries')->insert($chunk);
            }
        });

        return count($entries);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function entries(string $businessId): array
    {
        return [
            ...$this->formalRecordEntries($businessId),
            ...$this->documentEntries($businessId),
            ...$this->partnerEntries($businessId),
            ...$this->contributionEntries($businessId),
            ...$this->ownershipEntries($businessId),
            ...$this->partnerChangeEntries($businessId),
            ...$this->exitEntries($businessId),
            ...$this->closureEntries($businessId),
            ...$this->decisionEntries($businessId),
            ...$this->financePaymentEntries($businessId),
            ...$this->distributionEntries($businessId),
            ...$this->riskEntries($businessId),
            ...$this->continuityEntries($businessId),
            ...$this->conflictEntries($businessId),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function formalRecordEntries(string $businessId): array
    {
        return DB::table('formal_record_versions as version')
            ->join(
                'formal_record_families as family',
                function ($join): void {
                    $join->on(
                        'family.id',
                        '=',
                        'version.formal_record_family_id',
                    )->on(
                        'family.business_id',
                        '=',
                        'version.business_id',
                    );
                },
            )
            ->where('version.business_id', $businessId)
            ->get([
                'version.id',
                'version.version_number',
                'version.change_summary',
                'family.record_type',
                'family.subject_type',
                'family.subject_id',
            ])
            ->map(fn (object $row): array => $this->entry(
                $businessId,
                'formal_record_version',
                (string) $row->id,
                sprintf(
                    '%s · v%d',
                    $this->humanize((string) $row->record_type),
                    (int) $row->version_number,
                ),
                $this->nullable((string) ($row->change_summary ?? '')),
                implode(' ', array_filter([
                    (string) $row->record_type,
                    (string) $row->subject_type,
                    (string) $row->subject_id,
                    (string) ($row->change_summary ?? ''),
                ])),
                null,
            ))
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function documentEntries(string $businessId): array
    {
        return DB::table('documents')
            ->where('business_id', $businessId)
            ->get(['id', 'title', 'category'])
            ->map(fn (object $row): array => $this->entry(
                $businessId,
                'document',
                (string) $row->id,
                (string) $row->title,
                $this->humanize((string) $row->category),
                implode(' ', [
                    (string) $row->title,
                    (string) $row->category,
                ]),
                '/records/documents',
            ))
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function partnerEntries(string $businessId): array
    {
        return DB::table('partners')
            ->where('business_id', $businessId)
            ->get(['id', 'display_name', 'legal_name', 'status'])
            ->map(fn (object $row): array => $this->entry(
                $businessId,
                'partner',
                (string) $row->id,
                (string) $row->display_name,
                'Partner · '.$this->humanize((string) $row->status),
                implode(' ', array_filter([
                    (string) $row->display_name,
                    (string) ($row->legal_name ?? ''),
                    (string) $row->status,
                ])),
                '/partnership',
            ))
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function contributionEntries(string $businessId): array
    {
        return DB::table('contributions as contribution')
            ->leftJoin('partners as partner', function ($join): void {
                $join->on(
                    'partner.id',
                    '=',
                    'contribution.partner_id',
                )->on(
                    'partner.business_id',
                    '=',
                    'contribution.business_id',
                );
            })
            ->where('contribution.business_id', $businessId)
            ->get([
                'contribution.id',
                'contribution.description',
                'contribution.contribution_type',
                'contribution.status',
                'contribution.currency',
                'partner.display_name as partner_name',
            ])
            ->map(fn (object $row): array => $this->entry(
                $businessId,
                'contribution',
                (string) $row->id,
                (string) $row->description,
                sprintf(
                    '%s · %s',
                    $this->humanize((string) $row->contribution_type),
                    $this->humanize((string) $row->status),
                ),
                implode(' ', array_filter([
                    (string) $row->description,
                    (string) $row->contribution_type,
                    (string) $row->status,
                    (string) $row->currency,
                    (string) ($row->partner_name ?? ''),
                ])),
                '/partnership',
            ))
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function ownershipEntries(string $businessId): array
    {
        return DB::table('ownership_register_versions')
            ->where('business_id', $businessId)
            ->get([
                'id',
                'version_number',
                'status',
                'currency',
                'issued_shares',
                'effective_from',
            ])
            ->map(fn (object $row): array => $this->entry(
                $businessId,
                'ownership_register_version',
                (string) $row->id,
                sprintf(
                    'Ownership Register · v%d',
                    (int) $row->version_number,
                ),
                $this->humanize((string) $row->status),
                implode(' ', array_filter([
                    'ownership register',
                    (string) $row->status,
                    (string) $row->currency,
                    (string) $row->issued_shares,
                    (string) ($row->effective_from ?? ''),
                ])),
                '/partnership',
            ))
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function partnerChangeEntries(string $businessId): array
    {
        return DB::table('partner_change_cases')
            ->where('business_id', $businessId)
            ->get([
                'id',
                'case_number',
                'transaction_type',
                'status',
                'valuation_method',
                'rights_impact_summary',
            ])
            ->map(fn (object $row): array => $this->entry(
                $businessId,
                'partner_change_case',
                (string) $row->id,
                (string) $row->case_number,
                sprintf(
                    '%s · %s',
                    $this->humanize((string) $row->transaction_type),
                    $this->humanize((string) $row->status),
                ),
                implode(' ', array_filter([
                    (string) $row->case_number,
                    (string) $row->transaction_type,
                    (string) $row->status,
                    (string) ($row->valuation_method ?? ''),
                    (string) ($row->rights_impact_summary ?? ''),
                ])),
                '/changes/partner-changes',
            ))
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function exitEntries(string $businessId): array
    {
        return DB::table('exit_cases')
            ->where('business_id', $businessId)
            ->get([
                'id',
                'case_number',
                'trigger',
                'trigger_detail',
                'notice_summary',
                'share_treatment',
                'valuation_method',
                'leaver_rule_reference',
                'payment_terms_summary',
                'status',
            ])
            ->map(fn (object $row): array => $this->entry(
                $businessId,
                'exit_case',
                (string) $row->id,
                (string) $row->case_number,
                sprintf(
                    '%s · %s',
                    $this->humanize((string) $row->trigger),
                    $this->humanize((string) $row->status),
                ),
                implode(' ', array_filter([
                    (string) $row->case_number,
                    (string) $row->trigger,
                    (string) ($row->trigger_detail ?? ''),
                    (string) ($row->notice_summary ?? ''),
                    (string) ($row->share_treatment ?? ''),
                    (string) ($row->valuation_method ?? ''),
                    (string) ($row->leaver_rule_reference ?? ''),
                    (string) ($row->payment_terms_summary ?? ''),
                    (string) $row->status,
                ])),
                '/changes/exit',
            ))
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function closureEntries(string $businessId): array
    {
        return DB::table('closure_cases')
            ->where('business_id', $businessId)
            ->get([
                'id',
                'case_number',
                'trigger',
                'trigger_detail',
                'jurisdiction_reference',
                'legal_entity_reference',
                'status',
            ])
            ->map(fn (object $row): array => $this->entry(
                $businessId,
                'closure_case',
                (string) $row->id,
                (string) $row->case_number,
                sprintf(
                    '%s · %s',
                    $this->humanize((string) $row->trigger),
                    $this->humanize((string) $row->status),
                ),
                implode(' ', array_filter([
                    (string) $row->case_number,
                    (string) $row->trigger,
                    (string) ($row->trigger_detail ?? ''),
                    (string) $row->jurisdiction_reference,
                    (string) ($row->legal_entity_reference ?? ''),
                    (string) $row->status,
                ])),
                '/changes/closure',
            ))
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function decisionEntries(string $businessId): array
    {
        return DB::table('decisions')
            ->where('business_id', $businessId)
            ->get([
                'id',
                'decision_type',
                'status',
                'outcome',
            ])
            ->map(fn (object $row): array => $this->entry(
                $businessId,
                'governance_decision',
                (string) $row->id,
                'Decision · '.$this->humanize((string) $row->decision_type),
                implode(' · ', array_filter([
                    $this->humanize((string) $row->status),
                    $row->outcome === null
                        ? null
                        : $this->humanize((string) $row->outcome),
                ])),
                implode(' ', array_filter([
                    (string) $row->decision_type,
                    (string) $row->status,
                    (string) ($row->outcome ?? ''),
                ])),
                '/governance',
            ))
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function financePaymentEntries(string $businessId): array
    {
        return DB::table('finance_payments')
            ->where('business_id', $businessId)
            ->get([
                'id',
                'transaction_type',
                'payee_reference',
                'description',
                'payment_reference',
                'status',
                'currency',
            ])
            ->map(fn (object $row): array => $this->entry(
                $businessId,
                'finance_payment',
                (string) $row->id,
                'Payment · '.(string) $row->payee_reference,
                sprintf(
                    '%s · %s',
                    $this->humanize((string) $row->transaction_type),
                    $this->humanize((string) $row->status),
                ),
                implode(' ', array_filter([
                    (string) $row->transaction_type,
                    (string) $row->payee_reference,
                    (string) ($row->description ?? ''),
                    (string) ($row->payment_reference ?? ''),
                    (string) $row->status,
                    (string) $row->currency,
                ])),
                '/finance',
            ))
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function distributionEntries(string $businessId): array
    {
        return DB::table('distribution_runs')
            ->where('business_id', $businessId)
            ->get([
                'id',
                'period_start',
                'period_end',
                'record_date',
                'status',
                'currency',
                'notes',
            ])
            ->map(fn (object $row): array => $this->entry(
                $businessId,
                'distribution_run',
                (string) $row->id,
                sprintf(
                    'Distribution · %s to %s',
                    (string) $row->period_start,
                    (string) $row->period_end,
                ),
                $this->humanize((string) $row->status),
                implode(' ', array_filter([
                    'distribution',
                    (string) $row->period_start,
                    (string) $row->period_end,
                    (string) $row->record_date,
                    (string) $row->status,
                    (string) $row->currency,
                    (string) ($row->notes ?? ''),
                ])),
                '/rewards',
            ))
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function riskEntries(string $businessId): array
    {
        $items = DB::table('risk_items')
            ->where('business_id', $businessId)
            ->get([
                'id',
                'title',
                'category',
                'description',
                'warning_indicator',
                'mitigation',
                'response_plan',
                'status',
            ])
            ->map(fn (object $row): array => $this->entry(
                $businessId,
                'risk_item',
                (string) $row->id,
                (string) $row->title,
                sprintf(
                    '%s · %s',
                    $this->humanize((string) $row->category),
                    $this->humanize((string) $row->status),
                ),
                implode(' ', array_filter([
                    (string) $row->title,
                    (string) $row->category,
                    (string) $row->description,
                    (string) ($row->warning_indicator ?? ''),
                    (string) $row->mitigation,
                    (string) $row->response_plan,
                    (string) $row->status,
                ])),
                '/risk',
            ))
            ->all();

        $protections = DB::table('risk_protection_records')
            ->where('business_id', $businessId)
            ->get([
                'id',
                'protection_type',
                'covered_subject',
                'provider',
                'policy_reference',
                'status',
            ])
            ->map(fn (object $row): array => $this->entry(
                $businessId,
                'risk_protection',
                (string) $row->id,
                (string) $row->covered_subject,
                sprintf(
                    '%s · %s',
                    $this->humanize((string) $row->protection_type),
                    $this->humanize((string) $row->status),
                ),
                implode(' ', array_filter([
                    (string) $row->protection_type,
                    (string) $row->covered_subject,
                    (string) ($row->provider ?? ''),
                    (string) ($row->policy_reference ?? ''),
                    (string) $row->status,
                ])),
                '/risk',
            ))
            ->all();

        $incidents = DB::table('risk_incidents')
            ->where('business_id', $businessId)
            ->get([
                'id',
                'incident_type',
                'description',
                'business_impact',
                'immediate_action',
                'status',
            ])
            ->map(fn (object $row): array => $this->entry(
                $businessId,
                'risk_incident',
                (string) $row->id,
                'Risk Incident · '.$this->humanize(
                    (string) $row->incident_type,
                ),
                $this->humanize((string) $row->status),
                implode(' ', [
                    (string) $row->incident_type,
                    (string) $row->description,
                    (string) $row->business_impact,
                    (string) $row->immediate_action,
                    (string) $row->status,
                ]),
                '/risk',
            ))
            ->all();

        $tests = DB::table('risk_control_tests')
            ->where('business_id', $businessId)
            ->get([
                'id',
                'control_name',
                'scenario',
                'result',
                'gap_found',
                'corrective_action',
            ])
            ->map(fn (object $row): array => $this->entry(
                $businessId,
                'risk_control_test',
                (string) $row->id,
                (string) $row->control_name,
                $this->humanize((string) $row->result),
                implode(' ', array_filter([
                    (string) $row->control_name,
                    (string) $row->scenario,
                    (string) $row->result,
                    (string) ($row->gap_found ?? ''),
                    (string) ($row->corrective_action ?? ''),
                ])),
                '/risk',
            ))
            ->all();

        return [...$items, ...$protections, ...$incidents, ...$tests];
    }

    /** @return list<array<string,mixed>> */
    private function continuityEntries(string $businessId): array
    {
        $tests = DB::table('continuity_tests')
            ->where('business_id', $businessId)
            ->get([
                'id',
                'scenario_name',
                'scenario',
                'result',
                'failed_items',
                'improvement_actions',
            ])
            ->map(fn (object $row): array => $this->entry(
                $businessId,
                'continuity_test',
                (string) $row->id,
                (string) $row->scenario_name,
                $this->humanize((string) $row->result),
                implode(' ', array_filter([
                    (string) $row->scenario_name,
                    (string) $row->scenario,
                    (string) $row->result,
                    (string) ($row->failed_items ?? ''),
                    (string) ($row->improvement_actions ?? ''),
                ])),
                '/continuity',
            ))
            ->all();

        $activations = DB::table(
            'continuity_emergency_access_activations',
        )
            ->where('business_id', $businessId)
            ->get([
                'id',
                'required_decision_type',
                'trigger',
                'reason',
                'status',
            ])
            ->map(fn (object $row): array => $this->entry(
                $businessId,
                'continuity_emergency_access_activation',
                (string) $row->id,
                'Emergency Access · '.$this->humanize(
                    (string) $row->status,
                ),
                $this->nullable((string) $row->trigger),
                implode(' ', array_filter([
                    (string) ($row->required_decision_type ?? ''),
                    (string) $row->trigger,
                    (string) $row->reason,
                    (string) $row->status,
                ])),
                '/continuity',
            ))
            ->all();

        return [...$tests, ...$activations];
    }

    /** @return list<array<string,mixed>> */
    private function conflictEntries(string $businessId): array
    {
        return DB::table('conflict_cases')
            ->where('business_id', $businessId)
            ->get([
                'id',
                'case_number',
                'conflict_type',
                'description',
                'business_impact',
                'urgency',
                'stage',
                'status',
            ])
            ->map(fn (object $row): array => $this->entry(
                $businessId,
                'conflict_case',
                (string) $row->id,
                (string) $row->case_number,
                sprintf(
                    '%s · %s',
                    $this->humanize((string) $row->conflict_type),
                    $this->humanize((string) $row->status),
                ),
                implode(' ', [
                    (string) $row->case_number,
                    (string) $row->conflict_type,
                    (string) $row->description,
                    (string) $row->business_impact,
                    (string) $row->urgency,
                    (string) $row->stage,
                    (string) $row->status,
                ]),
                '/conflict',
            ))
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function entry(
        string $businessId,
        string $sourceType,
        string $sourceId,
        string $title,
        ?string $snippet,
        string $searchText,
        ?string $route,
    ): array {
        $now = now();

        return [
            'id' => (string) Str::uuid7(),
            'business_id' => $businessId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'title' => $this->clean($title),
            'snippet' => $this->nullable($snippet),
            'search_text' => $this->clean($searchText),
            'route' => $route,
            'indexed_at' => $now,
            'created_at' => $now,
        ];
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function nullable(string $value): ?string
    {
        $clean = $this->clean($value);

        return $clean === '' ? null : $clean;
    }

    private function humanize(string $value): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $value));
    }
}
