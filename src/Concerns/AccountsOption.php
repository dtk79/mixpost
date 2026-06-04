<?php

namespace Inovector\Mixpost\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Inovector\Mixpost\Models\Account;

trait AccountsOption
{
    public function accounts()
    {
        $ids = $this->optionValues('accounts');
        $providers = $this->optionValues('providers');

        return Account::when(count($ids), function (Builder $query) use ($ids) {
            $query->whereIn('id', $ids);
        })->when(count($providers), function (Builder $query) use ($providers) {
            $query->whereIn('provider', $providers);
        })->get();
    }

    protected function optionValues(string $option): array
    {
        return collect(Arr::wrap($this->option($option)))
            ->flatMap(fn ($value) => explode(',', $value))
            ->map(fn ($value) => trim($value))
            ->filter()
            ->values()
            ->all();
    }
}
