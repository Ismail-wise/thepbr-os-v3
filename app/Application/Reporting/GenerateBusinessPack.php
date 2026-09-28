<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Access\ResolveMembershipCapabilities;
use App\Application\Events\RecordBusinessOccurrence;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Events\ValueObjects\SafeBusinessEventPayload;
use App\Domain\Reporting\Enums\BusinessPackStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Reporting\BusinessPackExport;
use App\Infrastructure\Reporting\BusinessPackRenderer;
use App\Infrastructure\Storage\Documents\PrivateDocumentStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class GenerateBusinessPack
{
    public function __construct(
        private readonly ResolveMembershipCapabilities $memberships,
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly AuthorizeBusinessPackDownload $sourceAuthorization,
        private readonly BusinessPackRenderer $renderer,
        private readonly PrivateDocumentStorage $storage,
        private readonly RecordBusinessOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $exportId,
    ): ?BusinessPackExport {
        $membership = $this->memberships->activeMembership(
            $user,
            $business,
        );

        if (
            $membership === null
            || ! $this->authorize->decide(
                $user,
                $business,
                $business,
                new Capability(CapabilityCatalog::REPORTS_MANAGE),
            )->allowed
        ) {
            return null;
        }

        $export = BusinessPackExport::query()
            ->where('business_id', $business->getKey())
            ->whereKey($exportId)
            ->first();

        if (
            $export === null
            || $export->status !== BusinessPackStatus::ManifestFrozen
            || (string) $export->requested_by_membership_id
                !== (string) $membership->getKey()
        ) {
            return null;
        }

        $manifest = $export->frozen_manifest;

        if (
            ! is_array($manifest)
            || ! hash_equals(
                (string) $export->manifest_hash,
                $this->hash($manifest),
            )
        ) {
            throw new RuntimeException(
                'Frozen Business Pack manifest integrity check failed.',
            );
        }

        foreach ((array) ($manifest['sources'] ?? []) as $source) {
            if (! is_array($source)) {
                throw new RuntimeException(
                    'Frozen Business Pack source entry is invalid.',
                );
            }

            $expectedHash = (string) ($source['source_hash'] ?? '');
            $currentHash = $this->sourceAuthorization
                ->authorizedSourceHash(
                    $user,
                    $business,
                    $source,
                );

            if (
                $expectedHash === ''
                || $currentHash === null
                || ! hash_equals($expectedHash, $currentHash)
            ) {
                return null;
            }
        }

        $storageKey = null;

        try {
            $export = DB::transaction(function () use (
                $business,
                $exportId,
                $membership,
            ): BusinessPackExport {
                $locked = BusinessPackExport::query()
                    ->where('business_id', $business->getKey())
                    ->whereKey($exportId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $locked->status
                    !== BusinessPackStatus::ManifestFrozen
                ) {
                    throw new RuntimeException(
                        'Business Pack generation state changed.',
                    );
                }

                $locked->status = BusinessPackStatus::Generating;
                $locked->save();

                $this->transition(
                    $business,
                    $exportId,
                    BusinessPackStatus::ManifestFrozen,
                    BusinessPackStatus::Generating,
                    (string) $membership->getKey(),
                );

                return $locked->fresh();
            });

            $rendered = $this->renderer->render($export);
            $storageKey = implode('/', [
                'businesses',
                (string) $business->getKey(),
                'reports',
                'business-packs',
                $exportId,
                (string) Str::uuid7(),
            ]);

            $stream = fopen('php://temp', 'w+b');

            if (! is_resource($stream)) {
                throw new RuntimeException(
                    'Business Pack render stream could not be opened.',
                );
            }

            try {
                fwrite($stream, $rendered['content']);
                rewind($stream);
                $this->storage->write($storageKey, $stream);
            } finally {
                fclose($stream);
            }

            DB::transaction(function () use (
                $business,
                $exportId,
                $membership,
                $storageKey,
                $rendered,
            ): void {
                $locked = BusinessPackExport::query()
                    ->where('business_id', $business->getKey())
                    ->whereKey($exportId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status !== BusinessPackStatus::Generating) {
                    throw new RuntimeException(
                        'Business Pack generation state changed before verification.',
                    );
                }

                $locked->storage_key = $storageKey;
                $locked->output_filename = $rendered['filename'];
                $locked->mime_type = $rendered['mime_type'];
                $locked->size_bytes = $rendered['size_bytes'];
                $locked->content_sha256 = $rendered['content_sha256'];
                $locked->generated_at = now();
                $locked->status = BusinessPackStatus::Verifying;
                $locked->save();

                $this->transition(
                    $business,
                    $exportId,
                    BusinessPackStatus::Generating,
                    BusinessPackStatus::Verifying,
                    (string) $membership->getKey(),
                );
            });

            $readStream = $this->storage->read($storageKey);

            try {
                $storedContent = stream_get_contents($readStream);
            } finally {
                fclose($readStream);
            }

            if (
                ! is_string($storedContent)
                || strlen($storedContent) !== $rendered['size_bytes']
                || ! hash_equals(
                    $rendered['content_sha256'],
                    hash('sha256', $storedContent),
                )
            ) {
                throw new RuntimeException(
                    'Private Business Pack object verification failed.',
                );
            }

            $export = DB::transaction(function () use (
                $business,
                $exportId,
                $membership,
            ): BusinessPackExport {
                $locked = BusinessPackExport::query()
                    ->where('business_id', $business->getKey())
                    ->whereKey($exportId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status !== BusinessPackStatus::Verifying) {
                    throw new RuntimeException(
                        'Business Pack verification state changed.',
                    );
                }

                $locked->verified_at = now();
                $locked->available_at = now();
                $locked->status = BusinessPackStatus::Available;
                $locked->save();

                $this->transition(
                    $business,
                    $exportId,
                    BusinessPackStatus::Verifying,
                    BusinessPackStatus::Available,
                    (string) $membership->getKey(),
                );

                return $locked->fresh();
            });

            $this->recordOccurrence(
                $user,
                $business,
                'reporting.business_pack.available',
                $exportId,
                [
                    'manifest_hash' => (string) $export->manifest_hash,
                    'content_sha256' => (string) $export->content_sha256,
                    'size_bytes' => (int) $export->size_bytes,
                    'output_language' => (string) $export->output_language,
                ],
            );

            return $export;
        } catch (Throwable $exception) {
            if ($storageKey !== null) {
                try {
                    $this->storage->deleteExact($storageKey);
                } catch (Throwable) {
                }
            }

            $this->markFailed(
                $business,
                $exportId,
                (string) $membership->getKey(),
            );

            throw $exception;
        }
    }

    private function markFailed(
        Business $business,
        string $exportId,
        string $membershipId,
    ): void {
        DB::transaction(function () use (
            $business,
            $exportId,
            $membershipId,
        ): void {
            $export = BusinessPackExport::query()
                ->where('business_id', $business->getKey())
                ->whereKey($exportId)
                ->lockForUpdate()
                ->first();

            if (
                $export === null
                || in_array($export->status, [
                    BusinessPackStatus::Available,
                    BusinessPackStatus::Failed,
                ], true)
            ) {
                return;
            }

            $from = $export->status;
            $export->status = BusinessPackStatus::Failed;
            $export->save();

            $this->transition(
                $business,
                $exportId,
                $from,
                BusinessPackStatus::Failed,
                $membershipId,
            );
        });
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION,
            ),
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalize($item),
                $value,
            );
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function transition(
        Business $business,
        string $exportId,
        BusinessPackStatus $from,
        BusinessPackStatus $to,
        string $membershipId,
    ): void {
        DB::table('business_pack_export_transitions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'business_pack_export_id' => $exportId,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'actor_membership_id' => $membershipId,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<string,bool|float|int|string|null>  $metadata
     */
    private function recordOccurrence(
        User $user,
        Business $business,
        string $action,
        string $exportId,
        array $metadata,
    ): void {
        $at = now();
        $correlationId = $this->occurrence->newCorrelationId();
        $actor = AuditActor::user((string) $user->getKey());
        $target = new OccurrenceTarget(
            (string) $business->getKey(),
            'business_pack_export',
            $exportId,
        );

        $this->occurrence->audit(
            $business,
            $actor,
            $action,
            $target,
            SafeAuditMetadata::from($metadata),
            $at,
            $correlationId,
        );

        $this->occurrence->businessEvent(
            $business,
            $action,
            $target,
            $target,
            SafeBusinessEventPayload::from($metadata),
            $at,
            $actor,
            $correlationId,
        );
    }
}
