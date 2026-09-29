<?php

namespace Inovector\Mixpost\Analytics\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\ImportedPost;
use Inovector\Mixpost\Support\PaginationData;
use Inovector\Mixpost\Util;

trait HasPaginatedContent
{
    protected array $contentParams = [];

    abstract protected function postInsightModel(): string;

    protected function content(Account $account): array
    {
        $perPage = min($this->contentParams['per_page'] ?? 25, 50);
        $sortBy = $this->contentParams['sort_by'] ?? null;
        $sortDir = strtolower($this->contentParams['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $insightColumn = $this->insightPostIdColumn();
        $postTable = (new ImportedPost)->getTable();
        [$rangeStart, $rangeEnd] = $this->postDateTimeRange();

        $query = ImportedPost::query()
            ->where("$postTable.account_id", $account->id)
            ->where("$postTable.created_at", '>=', $rangeStart)
            ->where("$postTable.created_at", '<', $rangeEnd);

        if ($account->provider === 'twitter') {
            $query->where(function ($query) use ($postTable) {
                $query->whereNull("$postTable.data->analytics_visible")
                    ->orWhere("$postTable.data->analytics_visible", true);
            });
        }

        $enumClass = $sortBy ? $this->resolveInsightEnumClass() : null;
        $sortTypeValue = $enumClass ? $this->resolveEnumValue($enumClass, $sortBy) : null;

        if ($sortTypeValue !== null) {
            $insightModel = $this->postInsightModel();
            $insightTable = (new $insightModel)->getTable();

            // The insight table repeats the post table's column names. Re-qualify the
            // workspace scope's `workspace_id` so the join does not make it ambiguous.
            $query->withoutWorkspace()
                ->where("$postTable.workspace_id", WorkspaceManager::current()->id)
                ->leftJoin($insightTable, function ($join) use ($insightTable, $insightColumn, $sortTypeValue, $postTable) {
                    $join->on("$insightTable.workspace_id", '=', "$postTable.workspace_id")
                        ->on("$insightTable.account_id", '=', "$postTable.account_id")
                        ->on("$insightTable.$insightColumn", '=', "$postTable.provider_post_id")
                        ->where("$insightTable.type", $sortTypeValue);
                })
                ->select("$postTable.*")
                ->orderBy("$insightTable.value", $sortDir);
        } else {
            $query->orderBy("$postTable.created_at", $sortDir);
        }

        $paginator = $query->paginate($perPage)->withQueryString();
        $posts = $paginator->items();

        if (empty($posts)) {
            return [
                'posts' => [],
                'pagination' => PaginationData::fromPaginator($paginator),
                'avg_metrics' => [],
            ];
        }

        $postIds = [];

        foreach ($posts as $post) {
            $postIds[] = $post->provider_post_id;
        }

        [$metrics, $deltas, $trends] = $this->buildPostSeries($account, $postIds, $insightColumn);

        $mixpostUuids = $this->getMixpostPostUuids($account, $postIds);

        $result = [];

        foreach ($posts as $post) {
            $data = $post->data ?? [];
            $providerPostId = $post->provider_post_id;

            $result[] = [
                'provider_post_id' => $providerPostId,
                'mixpost_post_uuid' => $mixpostUuids[$providerPostId] ?? null,
                'title' => $data['title'] ?? '',
                'text' => $post->text ?? '',
                'permalink' => $post->url ?? '',
                'link' => $data['link'] ?? '',
                'thumbnail' => ImportedPost::thumbnailUrl($post->thumbnail),
                'content_type' => $post->content_type,
                'created_at' => $post->created_at ? Util::dateTimeFormat($post->created_at) : null,
                'metrics' => $metrics[$providerPostId] ?? [],
                'deltas' => $deltas[$providerPostId] ?? [],
                'trends' => $trends[$providerPostId] ?? [],
            ];
        }

        return [
            'posts' => $result,
            'pagination' => PaginationData::fromPaginator($paginator),
            'avg_metrics' => $this->computeGlobalAvgMetrics($account, $paginator->total()),
        ];
    }

    /**
     * Read every snapshot of the given posts once and derive the current metrics,
     * the period delta and the daily trend from that single result set.
     *
     * @return array{0: array, 1: array, 2: array}
     */
    protected function buildPostSeries(Account $account, array $postIds, string $insightColumn): array
    {
        $typeNames = $this->insightTypeNames();

        if (! method_exists($this, 'historyModel')) {
            $model = $this->postInsightModel();

            $rows = $model::account($account->id)
                ->whereIn($insightColumn, $postIds)
                ->toBase()
                ->get([$insightColumn, 'type', 'value']);

            $metrics = [];

            foreach ($rows as $row) {
                if ($name = $typeNames[$row->type] ?? null) {
                    $metrics[$row->{$insightColumn}][$name] = (int) $row->value;
                }
            }

            return [$metrics, [], []];
        }

        $historyClass = $this->historyModel();
        $isDailyTotals = method_exists($this, 'insightValuesAreDailyTotals') && $this->insightValuesAreDailyTotals();

        $rows = $historyClass::account($account->id)
            ->whereIn($insightColumn, $postIds)
            ->where('date', '>=', $this->startDate)
            ->where('date', '<=', $this->endDate)
            ->orderBy('date')
            ->toBase()
            ->get([$insightColumn, 'type', 'value', 'date']);

        $series = [];

        foreach ($rows as $row) {
            $value = (int) $row->value;
            $entry = &$series[$row->{$insightColumn}][$row->type];

            if ($entry === null) {
                $entry = ['values' => [], 'labels' => [], 'first' => $value, 'last' => 0, 'sum' => 0];
            }

            // Cumulative values are stored as running totals, so the daily figure is the growth.
            $entry['values'][] = $isDailyTotals ? $value : $value - $entry['last'];
            $entry['labels'][] = substr((string) $row->date, 0, 10);
            $entry['last'] = $value;
            $entry['sum'] += $value;

            unset($entry);
        }

        $metrics = [];
        $deltas = [];
        $trends = [];

        foreach ($series as $postId => $byType) {
            $postMetrics = [];
            $postDeltas = [];
            $postTrends = [];

            foreach ($byType as $type => $entry) {
                $name = $typeNames[$type] ?? null;

                if ($name === null) {
                    continue;
                }

                $postTrends[$name] = [
                    'values' => $entry['values'],
                    'labels' => $entry['labels'],
                ];

                if ($isDailyTotals) {
                    $postMetrics[$name] = $entry['sum'];

                    if ($entry['sum']) {
                        $postDeltas[$name] = $entry['sum'];
                    }

                    continue;
                }

                $postMetrics[$name] = $entry['last'];
                $postDeltas[$name] = $entry['last'] - $entry['first'];
            }

            $metrics[$postId] = $postMetrics;
            $trends[$postId] = $postTrends;

            if ($postDeltas) {
                $deltas[$postId] = $postDeltas;
            }
        }

        return [$metrics, $deltas, $trends];
    }

    protected function computeGlobalAvgMetrics(Account $account, int $totalPosts): array
    {
        if ($totalPosts === 0) {
            return [];
        }

        $typeNames = $this->insightTypeNames();

        if (empty($typeNames)) {
            return [];
        }

        $insightColumn = $this->insightPostIdColumn();
        $isDailyTotals = method_exists($this, 'insightValuesAreDailyTotals') && $this->insightValuesAreDailyTotals();

        if ($isDailyTotals && method_exists($this, 'historyModel')) {
            $historyClass = $this->historyModel();

            $query = $historyClass::account($account->id)
                ->where('date', '>=', $this->startDate)
                ->where('date', '<=', $this->endDate);
        } else {
            $model = $this->postInsightModel();

            $query = $model::account($account->id);
        }

        [$rangeStart, $rangeEnd] = $this->postDateTimeRange();
        $postTable = (new ImportedPost)->getTable();
        $workspaceId = WorkspaceManager::current()->id;

        $sums = $query
            ->whereIn($insightColumn, function ($subQuery) use ($postTable, $workspaceId, $account, $rangeStart, $rangeEnd) {
                $subQuery->select('provider_post_id')
                    ->from($postTable)
                    ->where('workspace_id', $workspaceId)
                    ->where('account_id', $account->id)
                    ->where('created_at', '>=', $rangeStart)
                    ->where('created_at', '<', $rangeEnd);

                if ($account->provider === 'twitter') {
                    $subQuery->where(function ($query) {
                        $query->whereNull('data->analytics_visible')
                            ->orWhere('data->analytics_visible', true);
                    });
                }
            })
            ->select('type', DB::raw('SUM(value) as total'))
            ->groupBy('type')
            ->pluck('total', 'type');

        $averages = [];

        foreach ($typeNames as $typeValue => $name) {
            $averages[$name] = round((int) ($sums[$typeValue] ?? 0) / $totalPosts, 1);
        }

        return $averages;
    }

    /**
     * @return array{0: string, 1: string} Inclusive start and exclusive end of the period.
     */
    protected function postDateTimeRange(): array
    {
        return [
            "$this->startDate 00:00:00",
            Carbon::parse($this->endDate, 'UTC')->addDay()->toDateString().' 00:00:00',
        ];
    }

    protected function insightTypeNames(): array
    {
        $enumClass = $this->resolveInsightEnumClass();

        if (! $enumClass || ! enum_exists($enumClass)) {
            return [];
        }

        $names = [];

        foreach ($enumClass::cases() as $case) {
            $names[$case->value] = $case->name;
        }

        return $names;
    }

    protected function resolveInsightEnumClass(): ?string
    {
        $model = $this->postInsightModel();
        $casts = (new $model)->getCasts();

        return $casts['type'] ?? null;
    }

    protected function resolveEnumValue(string $enumClass, string $name): ?int
    {
        if (! enum_exists($enumClass)) {
            return null;
        }

        foreach ($enumClass::cases() as $case) {
            if ($case->name === $name) {
                return $case->value;
            }
        }

        return null;
    }
}
