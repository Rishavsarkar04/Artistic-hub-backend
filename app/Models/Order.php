<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An order with snapshots of the customer, address and items at checkout. Created pending by
 * CheckoutService; confirmed only by a verified payment. Nothing is mass-assignable.
 */
class Order extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'placed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'order_number';
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->chaperone()->orderBy('id');
    }

    /** @return HasMany<Payment, $this> Newest last. */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->chaperone()->orderBy('id');
    }

    /**
     * The newest payment attempt: its status is the order's payment status (pending, paid, failed…).
     * Read through this relation instead of copying the status onto the order, so the two never differ;
     * eager-load it for lists with ->with('latestPayment').
     *
     * @return HasOne<Payment, $this>
     */
    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany('id');
    }
}
