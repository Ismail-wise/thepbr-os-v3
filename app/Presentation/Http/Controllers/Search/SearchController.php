<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Search;

use App\Application\Search\GlobalSearch;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class SearchController
{
    public function __invoke(
        Request $request,
        GlobalSearch $search,
    ): Response {
        $user = $request->user();
        $business = $request->attributes->get(
            EnsureCurrentBusinessContext::ATTRIBUTE_KEY,
        );

        if (! $user instanceof User || ! $business instanceof Business) {
            abort(403);
        }

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $results = $search->execute(
                $user,
                $business,
                (string) ($data['q'] ?? ''),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'q' => $exception->getMessage(),
            ]);
        }

        abort_if($results === null, 404);

        return Inertia::render('Search/Index', [
            'searchResults' => $results,
            'business' => [
                'id' => (string) $business->getKey(),
                'name' => (string) $business->name,
            ],
        ]);
    }
}
