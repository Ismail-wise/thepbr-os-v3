<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Records;

use App\Application\Documents\GetAuthorizedDocument;
use App\Application\Evidence\CreateDocumentVersionEvidence;
use App\Application\Evidence\EvidenceTargetRegistry;
use App\Application\Evidence\LinkEvidence;
use App\Application\Evidence\VerifyEvidence;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Evidence\Enums\EvidenceConfidentiality;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EvidenceController
{
    public function __construct(
        private readonly GetAuthorizedDocument $getAuthorizedDocument,
        private readonly EvidenceTargetRegistry $targets,
        private readonly CreateDocumentVersionEvidence $createEvidence,
        private readonly LinkEvidence $linkEvidence,
        private readonly VerifyEvidence $verifyEvidence,
    ) {}

    public function store(
        Request $request,
        string $document,
        string $documentVersion,
    ): RedirectResponse {
        [$user, $currentBusiness] = $this->context($request);

        $documentModel = $this->getAuthorizedDocument->execute(
            $user,
            $currentBusiness,
            $document,
            DocumentAccessRight::Manage,
            'records.manage',
        );

        abort_if($documentModel === null, 404);

        abort_unless(
            $documentModel->versions()
                ->whereKey($documentVersion)
                ->exists(),
            404,
        );

        $validated = $request->validate([
            'confidentiality' => [
                'required',
                Rule::enum(EvidenceConfidentiality::class),
            ],
            'source_date' => [
                'required',
                'date_format:Y-m-d',
            ],
        ]);

        $evidence = $this->createEvidence->execute(
            $user,
            $currentBusiness,
            $documentVersion,
            EvidenceConfidentiality::from(
                $validated['confidentiality'],
            ),
            new DateTimeImmutable($validated['source_date']),
        );

        abort_if($evidence === null, 404);

        return redirect()->route('records.documents.show', [
            'document' => $document,
        ]);
    }

    public function link(
        Request $request,
        string $evidence,
    ): RedirectResponse {
        [$user, $currentBusiness] = $this->context($request);

        $validated = $request->validate([
            'target_type' => [
                'required',
                'string',
                Rule::in($this->targets->supportedTypes()),
            ],
            'target_id' => [
                'required',
                'uuid',
            ],
        ]);

        $link = $this->linkEvidence->execute(
            $user,
            $currentBusiness,
            $evidence,
            $validated['target_type'],
            $validated['target_id'],
        );

        abort_if($link === null, 404);

        return back();
    }

    public function verify(
        Request $request,
        string $evidence,
    ): RedirectResponse {
        [$user, $currentBusiness] = $this->context($request);

        $validated = $request->validate([
            'verification_method' => [
                'required',
                'string',
                'max:120',
            ],
            'verification_note' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        $verified = $this->verifyEvidence->execute(
            $user,
            $currentBusiness,
            $evidence,
            trim($validated['verification_method']),
            $validated['verification_note'] ?? null,
        );

        abort_if($verified === null, 404);

        return back();
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
}
