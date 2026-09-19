<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Records;

use App\Application\Documents\AuthorizeDocumentAccess;
use App\Application\Documents\DownloadDocumentVersion;
use App\Application\Documents\GetAuthorizedDocument;
use App\Application\Documents\ListAuthorizedDocuments;
use App\Application\Documents\UploadDocument;
use App\Application\Documents\UploadDocumentVersion;
use App\Application\Evidence\EvidenceTargetRegistry;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Documents\Enums\DocumentCategory;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Evidence\Evidence;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentVaultController
{
    public function __construct(
        private readonly ListAuthorizedDocuments $listAuthorizedDocuments,
        private readonly GetAuthorizedDocument $getAuthorizedDocument,
        private readonly UploadDocument $uploadDocument,
        private readonly UploadDocumentVersion $uploadDocumentVersion,
        private readonly DownloadDocumentVersion $downloadDocumentVersion,
        private readonly AuthorizeDocumentAccess $authorization,
        private readonly EvidenceTargetRegistry $evidenceTargets,
    ) {}

    public function index(Request $request): Response
    {
        [$user, $currentBusiness] = $this->context($request);

        $documents = $this->listAuthorizedDocuments->execute(
            $user,
            $currentBusiness,
        );

        abort_if($documents === null, 403);

        $rows = $documents
            ->map(function ($document): array {
                $latest = $document->versions()
                    ->orderByDesc('version_number')
                    ->first([
                        'id',
                        'version_number',
                        'original_filename',
                        'mime_type',
                        'size_bytes',
                        'created_at',
                    ]);

                return [
                    'id' => (string) $document->getKey(),
                    'title' => (string) $document->title,
                    'category' => $document->category->value,
                    'createdAt' => $this->timestamp($document->created_at),
                    'versionCount' => $document->versions()->count(),
                    'latestVersion' => $latest === null
                        ? null
                        : [
                            'id' => (string) $latest->getKey(),
                            'number' => (int) $latest->version_number,
                            'filename' => (string) $latest->original_filename,
                            'mimeType' => (string) $latest->mime_type,
                            'sizeBytes' => (int) $latest->size_bytes,
                            'uploadedAt' => $this->timestamp($latest->created_at),
                        ],
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Records/Documents/Index', [
            'documents' => $rows,
            'categories' => array_map(
                static fn (DocumentCategory $category): string => $category->value,
                DocumentCategory::cases(),
            ),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$user, $currentBusiness] = $this->context($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::enum(DocumentCategory::class)],
            'file' => ['required', 'file'],
        ]);

        $file = $request->file('file');

        abort_unless($file instanceof UploadedFile, 422);

        try {
            $created = $this->uploadDocument->execute(
                $user,
                $currentBusiness,
                trim($validated['title']),
                DocumentCategory::from($validated['category']),
                $file,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        abort_if($created === null, 403);

        return redirect()->route('records.documents.show', [
            'document' => $created['document_id'],
        ]);
    }

    public function show(
        Request $request,
        string $document,
    ): Response {
        [$user, $currentBusiness] = $this->context($request);

        $documentModel = $this->getAuthorizedDocument->execute(
            $user,
            $currentBusiness,
            $document,
        );

        abort_if($documentModel === null, 404);

        $versions = $documentModel->versions()
            ->orderByDesc('version_number')
            ->get([
                'id',
                'document_id',
                'version_number',
                'original_filename',
                'mime_type',
                'size_bytes',
                'content_sha256',
                'uploaded_by_membership_id',
                'effective_from',
                'supersedes_document_version_id',
                'created_at',
            ]);

        $versionNumberById = $versions->mapWithKeys(
            static fn ($version): array => [
                (string) $version->getKey() => (int) $version->version_number,
            ],
        );

        $versionRows = $versions
            ->map(function ($version) use ($versionNumberById): array {
                $supersedes = $version->supersedes_document_version_id;

                return [
                    'id' => (string) $version->getKey(),
                    'number' => (int) $version->version_number,
                    'filename' => (string) $version->original_filename,
                    'mimeType' => (string) $version->mime_type,
                    'sizeBytes' => (int) $version->size_bytes,
                    'sha256' => (string) $version->content_sha256,
                    'uploadedByMembershipId' => (string) $version->uploaded_by_membership_id,
                    'uploadedAt' => $this->timestamp($version->created_at),
                    'effectiveFrom' => $this->dateValue($version->effective_from),
                    'supersedesVersionNumber' => $supersedes === null
                        ? null
                        : $versionNumberById->get((string) $supersedes),
                ];
            })
            ->values()
            ->all();

        $canManage = $this->authorization->allows(
            $user,
            $currentBusiness,
            $documentModel,
            DocumentAccessRight::Manage,
            'records.manage',
        ) !== null;

        $evidenceRows = [];

        if ($canManage && $versions->isNotEmpty()) {
            $evidenceRows = Evidence::query()
                ->where('business_id', $currentBusiness->getKey())
                ->whereIn('document_version_id', $versions->modelKeys())
                ->orderByDesc('created_at')
                ->get([
                    'id',
                    'document_version_id',
                    'confidentiality',
                    'source_date',
                    'submitted_by_membership_id',
                    'verified_at',
                    'verified_by_membership_id',
                    'verification_method',
                    'verification_note',
                    'created_at',
                ])
                ->map(function (Evidence $evidence) use (
                    $versionNumberById,
                ): array {
                    return [
                        'id' => (string) $evidence->getKey(),
                        'documentVersionId' => (string) $evidence->document_version_id,
                        'versionNumber' => $versionNumberById->get(
                            (string) $evidence->document_version_id,
                        ),
                        'confidentiality' => $evidence->confidentiality->value,
                        'sourceDate' => $this->dateValue(
                            $evidence->source_date,
                        ),
                        'submittedByMembershipId' => (string) $evidence->submitted_by_membership_id,
                        'verified' => $evidence->verified_at !== null,
                        'verifiedAt' => $this->timestamp(
                            $evidence->verified_at,
                        ),
                        'verifiedByMembershipId' => $evidence->verified_by_membership_id === null
                                ? null
                                : (string) $evidence->verified_by_membership_id,
                        'verificationMethod' => $evidence->verification_method,
                        'verificationNote' => $evidence->verification_note,
                    ];
                })
                ->values()
                ->all();
        }

        return Inertia::render('Records/Documents/Show', [
            'document' => [
                'id' => (string) $documentModel->getKey(),
                'title' => (string) $documentModel->title,
                'category' => $documentModel->category->value,
                'createdAt' => $this->timestamp($documentModel->created_at),
            ],
            'versions' => $versionRows,
            'evidence' => $evidenceRows,
            'evidenceTargetTypes' => $canManage
                ? $this->evidenceTargets->supportedTypes()
                : [],
            'canManage' => $canManage,
        ]);
    }

    public function storeVersion(
        Request $request,
        string $document,
    ): RedirectResponse {
        [$user, $currentBusiness] = $this->context($request);

        $request->validate([
            'file' => ['required', 'file'],
        ]);

        $file = $request->file('file');

        abort_unless($file instanceof UploadedFile, 422);

        try {
            $created = $this->uploadDocumentVersion->execute(
                $user,
                $currentBusiness,
                $document,
                $file,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        abort_if($created === null, 404);

        return redirect()->route('records.documents.show', [
            'document' => $document,
        ]);
    }

    public function downloadVersion(
        Request $request,
        string $document,
        string $documentVersion,
    ): StreamedResponse {
        [$user, $currentBusiness] = $this->context($request);

        $download = $this->downloadDocumentVersion->execute(
            $user,
            $currentBusiness,
            $document,
            $documentVersion,
        );

        abort_if($download === null, 404);
        abort_unless(is_resource($download['stream']), 500);

        $stream = $download['stream'];
        $filename = basename($download['filename']);

        return response()->streamDownload(
            static function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            $filename,
            [
                'Content-Type' => $download['mime_type'],
                'Content-Length' => (string) $download['size_bytes'],
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * @return array{User, Business}
     */
    private function context(Request $request): array
    {
        $user = $request->user();
        $currentBusiness = $request->attributes->get(
            EnsureCurrentBusinessContext::ATTRIBUTE_KEY,
        );

        if (! $user instanceof User || ! $currentBusiness instanceof Business) {
            abort(403);
        }

        return [$user, $currentBusiness];
    }

    private function timestamp(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface
            ? $value->format(DATE_ATOM)
            : null;
    }

    private function dateValue(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : null;
    }
}
