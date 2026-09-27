<?php

declare(strict_types=1);

namespace Tests\Feature\Conflict;

require_once __DIR__.'/F6EConflictPolicyTest.php';

use App\Application\Activity\ActivityTargetRegistry;
use App\Application\Activity\ListAuthorizedBusinessActivity;
use App\Application\Evidence\EvidenceTargetRegistry;
use App\Application\Evidence\LinkEvidence;
use App\Domain\Access\ValueObjects\Capability;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Events\BusinessEvent;
use App\Infrastructure\Persistence\Eloquent\Evidence\Evidence;
use Illuminate\Support\Str;

final class F6EConflictActivityPrivacyTest extends F6EConflictTestCase
{
    public function test_conflict_business_event_exposes_only_safe_metadata_and_restricted_target(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context);

        $event = BusinessEvent::query()
            ->where('business_id', $context['business']->getKey())
            ->where('event_type', 'conflict.case.opened')
            ->where('visibility_resource_id', $opened['id'])
            ->sole();

        self::assertSame('conflict_case', $event->visibility_resource_type);
        self::assertSame($opened['id'], (string) $event->visibility_resource_id);

        $payload = $event->payload;
        self::assertIsArray($payload);
        self::assertSame(
            ['revision', 'stage', 'status'],
            tap(array_keys($payload), static fn (array &$keys) => sort($keys)),
        );

        foreach ([
            'description',
            'business_impact',
            'allegation',
            'evidence',
            'settlement_terms',
            'participant',
            'case_number',
        ] as $sensitive) {
            self::assertArrayNotHasKey($sensitive, $payload);
        }
    }

    public function test_hidden_case_does_not_appear_in_generic_activity_projection(): void
    {
        $context = $this->context();
        $this->openCase($context);

        $this->grantCapability(
            $context['business'],
            $context['party'],
            'records.activity.view',
        );

        $activity = $this->app
            ->make(ListAuthorizedBusinessActivity::class)
            ->execute(
                $context['party_user'],
                $context['business'],
                new Capability('records.activity.view'),
            );

        self::assertNotNull($activity);
        self::assertSame([], $activity['items']);
        self::assertArrayNotHasKey('total', $activity);
    }

    public function test_activity_registry_resolves_conflict_case_only_inside_current_business(): void
    {
        $context = $this->context();
        $foreign = $this->context();
        $opened = $this->openCase($context);
        $foreignCase = $this->openCase($foreign);
        $registry = $this->app->make(ActivityTargetRegistry::class);

        self::assertSame(
            ConflictCase::class,
            $registry->resourceClass('conflict_case'),
        );
        self::assertNotNull($registry->find(
            $context['business'],
            'conflict_case',
            $opened['id'],
        ));
        self::assertNull($registry->find(
            $context['business'],
            'conflict_case',
            $foreignCase['id'],
        ));
    }

    public function test_hidden_and_missing_conflict_evidence_targets_fail_equivalently(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context);
        [, $evidence] = $this->evidence($context);

        foreach (['records.manage', 'conflict.manage'] as $capability) {
            $this->grantCapability(
                $context['business'],
                $context['party'],
                $capability,
            );
        }

        $document = Document::query()
            ->where('business_id', $context['business']->getKey())
            ->whereHas('versions', fn ($query) => $query->where(
                'id',
                $evidence->document_version_id,
            ))
            ->sole();

        DocumentAccessGrant::query()->create([
            'business_id' => $context['business']->getKey(),
            'membership_id' => $context['party']->getKey(),
            'document_id' => $document->getKey(),
            'right' => 'manage',
            'effect' => 'allow',
        ]);

        $service = $this->app->make(LinkEvidence::class);

        $hidden = $service->execute(
            $context['party_user'],
            $context['business'],
            (string) $evidence->getKey(),
            'conflict_case',
            $opened['id'],
        );
        $missing = $service->execute(
            $context['party_user'],
            $context['business'],
            (string) $evidence->getKey(),
            'conflict_case',
            (string) Str::uuid7(),
        );

        self::assertNull($hidden);
        self::assertNull($missing);
        $this->assertDatabaseCount('evidence_links', 0);
    }

    public function test_authorized_case_evidence_link_requires_separate_document_access(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context);
        [$document, $evidence] = $this->evidence($context);

        $service = $this->app->make(LinkEvidence::class);

        self::assertNull($service->execute(
            $context['user'],
            $context['business'],
            (string) $evidence->getKey(),
            'conflict_case',
            $opened['id'],
        ));

        DocumentAccessGrant::query()->create([
            'business_id' => $context['business']->getKey(),
            'membership_id' => $context['owner']->getKey(),
            'document_id' => $document->getKey(),
            'right' => 'manage',
            'effect' => 'allow',
        ]);

        $linked = $service->execute(
            $context['user'],
            $context['business'],
            (string) $evidence->getKey(),
            'conflict_case',
            $opened['id'],
        );

        self::assertNotNull($linked);
        $this->assertDatabaseHas('evidence_links', [
            'business_id' => $context['business']->getKey(),
            'evidence_id' => $evidence->getKey(),
            'target_type' => 'conflict_case',
            'target_id' => $opened['id'],
        ]);
    }

    public function test_evidence_target_registry_remains_closed_and_conflict_specific(): void
    {
        $registry = $this->app->make(EvidenceTargetRegistry::class);

        self::assertContains('conflict_case', $registry->supportedTypes());
        self::assertSame(
            'conflict.manage',
            $registry->requiredManageCapability('conflict_case'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $registry->requiredManageCapability('arbitrary_table');
    }

    /** @return array{Document,Evidence} */
    private function evidence(array $context): array
    {
        $document = Document::query()->create([
            'business_id' => $context['business']->getKey(),
            'title' => 'Restricted Conflict Evidence',
            'category' => 'corporate_legal',
            'created_by_membership_id' => $context['owner']->getKey(),
        ]);

        $version = DocumentVersion::query()->create([
            'business_id' => $context['business']->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'original_filename' => 'conflict-evidence.pdf',
            'storage_key' => 'tests/'.Str::uuid7().'/conflict-evidence.pdf',
            'size_bytes' => 256,
            'mime_type' => 'application/pdf',
            'content_sha256' => str_repeat('9', 64),
            'uploaded_by_membership_id' => $context['owner']->getKey(),
            'effective_from' => null,
            'supersedes_document_version_id' => null,
        ]);

        $evidence = Evidence::query()->create([
            'business_id' => $context['business']->getKey(),
            'document_version_id' => $version->getKey(),
            'confidentiality' => 'restricted',
            'source_date' => now()->toDateString(),
            'submitted_by_membership_id' => $context['owner']->getKey(),
            'verified_at' => null,
            'verified_by_membership_id' => null,
            'verification_method' => null,
            'verification_note' => null,
        ]);

        return [$document, $evidence];
    }
}
