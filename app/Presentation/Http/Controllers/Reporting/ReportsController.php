<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Reporting;

use App\Application\Reporting\AuthorizeBusinessPackDownload;
use App\Application\Reporting\CreateBusinessPack;
use App\Application\Reporting\GenerateBusinessPack;
use App\Application\Reporting\GetReportsWorkspace;
use App\Domain\Identity\Enums\LanguageMode;
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

final class ReportsController
{
    public function index(
        Request $request,
        GetReportsWorkspace $workspace,
    ): Response {
        [$user, $business] = $this->context($request);
        $payload = $workspace->execute($user, $business);

        abort_if($payload === null, 404);

        return Inertia::render('Reports/Index', [
            'reportsWorkspace' => $payload,
        ]);
    }

    public function create(
        Request $request,
        CreateBusinessPack $create,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'output_language' => [
                'required',
                Rule::enum(LanguageMode::class),
            ],
            'requested_scope' => [
                'required',
                'array',
                'min:1',
                'max:14',
            ],
            'requested_scope.*' => [
                'required',
                'string',
                'max:80',
            ],
        ]);

        try {
            $export = $create->execute(
                $user,
                $business,
                $data['requested_scope'],
                LanguageMode::from($data['output_language']),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'requested_scope' => $exception->getMessage(),
            ]);
        }

        abort_if($export === null, 404);

        return back();
    }

    public function generate(
        Request $request,
        string $export,
        GenerateBusinessPack $generate,
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
                'business_pack' => $exception->getMessage(),
            ]);
        }

        abort_if($generated === null, 404);

        return back();
    }

    public function download(
        Request $request,
        string $export,
        AuthorizeBusinessPackDownload $download,
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
