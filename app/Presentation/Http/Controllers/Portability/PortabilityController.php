<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Portability;

use App\Application\Portability\AuthorizeBusinessExportDownload;
use App\Application\Portability\ChangeWorkspaceArchiveState;
use App\Application\Portability\CreateBusinessExport;
use App\Application\Portability\GenerateBusinessExport;
use App\Application\Portability\GetPortabilityWorkspace;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PortabilityController
{
    public function index(
        Request $request,
        GetPortabilityWorkspace $workspace,
    ): Response {
        [$user, $business] = $this->context($request);

        $payload = $workspace->execute($user, $business);

        abort_if($payload === null, 404);

        return Inertia::render('Records/Portability', [
            'portabilityWorkspace' => $payload,
        ]);
    }

    public function archive(
        Request $request,
        ChangeWorkspaceArchiveState $archive,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $updated = $archive->archive(
                $user,
                $business,
                $data['reason'],
            );
        } catch (
            InvalidArgumentException|RuntimeException $exception
        ) {
            throw ValidationException::withMessages([
                'archive' => $exception->getMessage(),
            ]);
        }

        abort_if($updated === null, 404);

        return back();
    }

    public function unarchive(
        Request $request,
        ChangeWorkspaceArchiveState $archive,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $updated = $archive->unarchive(
                $user,
                $business,
                $data['reason'],
            );
        } catch (
            InvalidArgumentException|RuntimeException $exception
        ) {
            throw ValidationException::withMessages([
                'archive' => $exception->getMessage(),
            ]);
        }

        abort_if($updated === null, 404);

        return back();
    }

    public function createExport(
        Request $request,
        CreateBusinessExport $create,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'requested_categories' => [
                'required',
                'array',
                'min:1',
                'max:5',
            ],
            'requested_categories.*' => [
                'required',
                'string',
                Rule::in([
                    'business',
                    'formal_records',
                    'documents',
                    'ownership',
                    'partners',
                ]),
                'distinct',
            ],
        ]);

        try {
            $export = $create->execute(
                $user,
                $business,
                $data['requested_categories'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'requested_categories' => $exception->getMessage(),
            ]);
        }

        abort_if($export === null, 404);

        return back();
    }

    public function generateExport(
        Request $request,
        string $export,
        GenerateBusinessExport $generate,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        try {
            $generated = $generate->execute(
                $user,
                $business,
                $export,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'business_export' => $exception->getMessage(),
            ]);
        }

        abort_if($generated === null, 404);

        return back();
    }

    public function downloadExport(
        Request $request,
        string $export,
        AuthorizeBusinessExportDownload $download,
    ): StreamedResponse {
        [$user, $business] = $this->context($request);

        $file = $download->execute(
            $user,
            $business,
            $export,
        );

        abort_if($file === null, 404);
        abort_unless(is_resource($file['stream']), 500);

        $stream = $file['stream'];
        $filename = basename($file['filename']);

        return response()->streamDownload(
            static function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            $filename,
            [
                'Content-Type' => $file['mime_type'],
                'Content-Length' => (string) $file['size_bytes'],
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Content-SHA256' => $file['content_sha256'],
            ],
        );
    }

    /** @return array{User,Business} */
    private function context(Request $request): array
    {
        $user = $request->user();
        $business = $request->attributes->get(
            EnsureCurrentBusinessContext::ATTRIBUTE_KEY,
        );

        if (! $user instanceof User || ! $business instanceof Business) {
            abort(403);
        }

        return [$user, $business];
    }
}
