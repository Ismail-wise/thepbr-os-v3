<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Import;

use App\Application\Import\ConfirmImportRecords;
use App\Application\Import\CreateImportBatch;
use App\Application\Import\GetImportWorkspace;
use App\Application\Import\ParseImportBatch;
use App\Application\Import\ValidateImportBatch;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class ImportController
{
    public function index(
        Request $request,
        GetImportWorkspace $workspace,
    ): Response {
        [$user, $business] = $this->context($request);

        $selectedBatchId = $request->query('batch');
        $selectedBatchId = is_string($selectedBatchId)
            ? $selectedBatchId
            : null;

        $payload = $workspace->execute(
            $user,
            $business,
            $selectedBatchId,
        );

        abort_if($payload === null, 404);

        return Inertia::render('Import/Index', [
            'importWorkspace' => $payload,
        ]);
    }

    public function create(
        Request $request,
        CreateImportBatch $create,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'source_system' => ['required', 'string', 'max:120'],
            'schema_version' => ['required', 'string', 'max:80'],
            'intended_target' => [
                'required',
                Rule::in([
                    'partner',
                    'formal_record_amendment',
                ]),
            ],
            'source_file' => [
                'required',
                'file',
                'max:2048',
            ],
        ]);

        $file = $data['source_file'];

        if (! $file instanceof UploadedFile) {
            abort(422);
        }

        $extension = mb_strtolower(
            $file->getClientOriginalExtension(),
        );

        if (! in_array($extension, ['csv', 'json'], true)) {
            throw ValidationException::withMessages([
                'source_file' => 'Import file extension must be .csv or .json.',
            ]);
        }

        $path = $file->getRealPath();
        $content = $path === false ? false : file_get_contents($path);

        if (! is_string($content)) {
            throw ValidationException::withMessages([
                'source_file' => 'Import source file could not be read.',
            ]);
        }

        try {
            $batch = $create->execute(
                $user,
                $business,
                $extension,
                $data['source_system'],
                $file->getClientOriginalName(),
                $content,
                $data['schema_version'],
                $data['intended_target'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'source_file' => $exception->getMessage(),
            ]);
        }

        abort_if($batch === null, 404);

        return redirect()->route('import.index', [
            'batch' => $batch->getKey(),
        ]);
    }

    public function parse(
        Request $request,
        string $batch,
        ParseImportBatch $parse,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        try {
            $parsed = $parse->execute(
                $user,
                $business,
                $batch,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'import_batch' => $exception->getMessage(),
            ]);
        }

        abort_if($parsed === null, 404);

        return redirect()->route('import.index', [
            'batch' => $parsed->getKey(),
        ]);
    }

    public function validateBatch(
        Request $request,
        string $batch,
        ValidateImportBatch $validate,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        try {
            $validated = $validate->execute(
                $user,
                $business,
                $batch,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'import_batch' => $exception->getMessage(),
            ]);
        }

        abort_if($validated === null, 404);

        return redirect()->route('import.index', [
            'batch' => $validated->getKey(),
        ]);
    }

    public function confirm(
        Request $request,
        string $batch,
        ConfirmImportRecords $confirm,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'record_ids' => [
                'required',
                'array',
                'min:1',
                'max:5000',
            ],
            'record_ids.*' => [
                'required',
                'uuid',
                'distinct',
            ],
        ]);

        try {
            $result = $confirm->execute(
                $user,
                $business,
                $batch,
                $data['record_ids'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'record_ids' => $exception->getMessage(),
            ]);
        }

        abort_if($result === null, 404);

        return redirect()->route('import.index', [
            'batch' => $result['batch']->getKey(),
        ]);
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
