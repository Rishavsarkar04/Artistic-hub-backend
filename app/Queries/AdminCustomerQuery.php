<?php

namespace App\Queries;

use App\Data\CustomerListFilters;
use App\Enums\CustomerSort;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only query behind the admin customer list. Only users with the customer role;
 * soft-deleted users are left out by the User model's soft-delete scope.
 */
final class AdminCustomerQuery
{
    /** @return LengthAwarePaginator<int, User> */
    public function paginate(CustomerListFilters $filters): LengthAwarePaginator
    {
        $query = $this->customers()->with(['customerProfile.defaultAddress']);

        if ($filters->search !== null) {
            $this->applySearch($query, $filters->search);
        }

        match ($filters->sort) {
            CustomerSort::Newest => $query->latest('created_at')->latest('id'),
            CustomerSort::Oldest => $query->oldest('created_at')->oldest('id'),
            // Customers without a name yet (no profile) go last.
            CustomerSort::Name => $query->orderByRaw('name is null')->orderBy('name')->orderBy('id'),
        };

        return $query->paginate($filters->perPage)->withQueryString();
    }

    /** @return Builder<User> */
    private function customers(): Builder
    {
        return User::query()->role(Role::Customer);
    }

    /** Matches name, email or profile phone; LIKE wildcards typed by the admin are treated literally. */
    private function applySearch(Builder $query, string $search): void
    {
        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->where(function (Builder $where) use ($term) {
            $where->where('name', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhereHas('customerProfile', fn (Builder $profile) => $profile->where('phone', 'like', $term));
        });
    }
}
