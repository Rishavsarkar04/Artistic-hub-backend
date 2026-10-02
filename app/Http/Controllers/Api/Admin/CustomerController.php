<?php

namespace App\Http\Controllers\Api\Admin;

use App\Data\CustomerListFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListCustomersRequest;
use App\Http\Resources\AdminCustomerResource;
use App\Queries\AdminCustomerQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Read-only: admins can list customers, not change them. */
class CustomerController extends Controller
{
    public function __construct(private AdminCustomerQuery $adminCustomerQuery) {}

    /**
     * List customers.
     *
     * Customers only (no admins, no deleted accounts), paginated. Search matches name, email and phone.
     * The response also has `filters` (the filters applied, with defaults filled in) and
     * `filter_options` (the values `sort` accepts).
     */
    public function index(ListCustomersRequest $request): AnonymousResourceCollection
    {
        $filters = $request->toFilters();

        return AdminCustomerResource::collection($this->adminCustomerQuery->paginate($filters))
            ->additional([
                'filters' => $filters->toArray(),
                'filter_options' => CustomerListFilters::options(),
            ]);
    }
}
