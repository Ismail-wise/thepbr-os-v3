<?php

declare(strict_types=1);

namespace App\Application\Search;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Search\SearchIndexEntry;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class GlobalSearch
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly SearchIndexProjector $projector,
        private readonly SearchVisibility $visibility,
    ) {}

    /**
     * @return array{
     *   query:string,
     *   count:int,
     *   items:list<array{
     *     source_type:string,
     *     source_id:string,
     *     title:string,
     *     snippet:string|null,
     *     route:string|null,
     *     rank:float
     *   }>,
     *   suggestions:list<string>
     * }|null
     */
    public function execute(
        User $user,
        Business $business,
        string $query,
        int $limit = 30,
    ): ?array {
        if (! $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability(CapabilityCatalog::SEARCH_VIEW),
        )->allowed) {
            return null;
        }

        $term = trim($query);

        if (mb_strlen($term) > 200) {
            throw new InvalidArgumentException(
                'Search query must not exceed 200 characters.',
            );
        }

        if ($term === '') {
            return [
                'query' => '',
                'count' => 0,
                'items' => [],
                'suggestions' => [],
            ];
        }

        $limit = max(1, min($limit, 50));

        /*
         * Search is a derived projection. Rebuilding never creates canonical
         * truth, and every returned row is still authorized against live
         * source state after this projection step.
         */
        $this->projector->rebuildBusiness($business);

        $base = $this->matchingQuery(
            (string) $business->getKey(),
            $term,
        );

        $visibleCount = 0;
        $items = [];
        $suggestions = [];
        $offset = 0;

        while (true) {
            $batch = (clone $base)
                ->offset($offset)
                ->limit(self::BATCH_SIZE)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            foreach ($batch as $entry) {
                if (! $this->visibility->allows(
                    $user,
                    $business,
                    (string) $entry->source_type,
                    (string) $entry->source_id,
                )) {
                    continue;
                }

                $visibleCount++;

                if (count($items) < $limit) {
                    $items[] = [
                        'source_type' => (string) $entry->source_type,
                        'source_id' => (string) $entry->source_id,
                        'title' => (string) $entry->title,
                        'snippet' => $entry->snippet === null
                            ? null
                            : (string) $entry->snippet,
                        'route' => $entry->route === null
                            ? null
                            : (string) $entry->route,
                        'rank' => (float) $entry->search_rank,
                    ];
                }

                if (
                    count($suggestions) < 8
                    && ! in_array(
                        (string) $entry->title,
                        $suggestions,
                        true,
                    )
                ) {
                    $suggestions[] = (string) $entry->title;
                }
            }

            if ($batch->count() < self::BATCH_SIZE) {
                break;
            }

            $offset += self::BATCH_SIZE;
        }

        return [
            'query' => $term,
            'count' => $visibleCount,
            'items' => $items,
            'suggestions' => $suggestions,
        ];
    }

    private function matchingQuery(
        string $businessId,
        string $term,
    ): Builder {
        $titlePattern = '%'.$this->escapeLike($term).'%';

        return SearchIndexEntry::query()
            ->where('business_id', $businessId)
            ->where(function (Builder $query) use (
                $term,
                $titlePattern,
            ): void {
                $query->whereRaw(
                    "search_vector @@ websearch_to_tsquery('simple', ?)",
                    [$term],
                )->orWhereRaw(
                    "title ILIKE ? ESCAPE '\\'",
                    [$titlePattern],
                );
            })
            ->select([
                'id',
                'business_id',
                'source_type',
                'source_id',
                'title',
                'snippet',
                'route',
            ])
            ->selectRaw(
                "CASE
                    WHEN search_vector @@ websearch_to_tsquery('simple', ?)
                    THEN ts_rank_cd(
                        search_vector,
                        websearch_to_tsquery('simple', ?)
                    )
                    ELSE 0
                END AS search_rank",
                [$term, $term],
            )
            ->orderByDesc('search_rank')
            ->orderBy('title')
            ->orderBy('id');
    }

    private function escapeLike(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value,
        );
    }
}
