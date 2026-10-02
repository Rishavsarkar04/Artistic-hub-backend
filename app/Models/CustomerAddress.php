<?php

namespace App\Models;

use App\Enums\AddressLabel;
use App\Models\Concerns\HasPublicId;
use Database\Factories\CustomerAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved shipping address. Orders copy it into their own snapshot, so editing it never changes past orders.
 * `customer_profile_id` and `is_default` are not fillable: CustomerAddressService sets them, keeping
 * exactly one default per customer.
 */
#[Fillable([
    'label',
    'recipient_name',
    'phone',
    'address_line_1',
    'address_line_2',
    'city',
    'state',
    'postal_code',
    'country',
])]
class CustomerAddress extends Model
{
    /** @use HasFactory<CustomerAddressFactory> */
    use HasFactory, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'label' => 'home',
        'is_default' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'label' => AddressLabel::class,
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }
}
