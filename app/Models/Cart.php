<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's server cart: at most one per customer, created on the first add. Changed only through
 * CartService. Items keep no prices; totals are worked out from current variant prices on every read.
 */
class Cart extends Model
{
    /** @return BelongsTo<CustomerProfile, $this> */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return HasMany<CartItem, $this> In the order they were added. */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->chaperone()->orderBy('id');
    }

    /** Total units across all items. */
    public function itemCount(): int
    {
        return (int) $this->items->sum('quantity');
    }

    /** Sum of the subtotals (selling price × quantity) of items that can be bought as they are (no issue). */
    public function subtotal(): string
    {
        return $this->sumOfBuyableItems(fn (CartItem $item) => $item->subtotal());
    }

    /** Sum of original price (MRP) × quantity of items that can be bought as they are. */
    public function mrpTotal(): string
    {
        return $this->sumOfBuyableItems(fn (CartItem $item) => $item->originalSubtotal());
    }

    /** What the customer saves on those items: MRP total minus subtotal. */
    public function discount(): string
    {
        return Money::subtract($this->mrpTotal(), $this->subtotal());
    }

    /** @param  \Closure(CartItem): string  $amountOf */
    private function sumOfBuyableItems(\Closure $amountOf): string
    {
        return $this->items
            ->filter(fn (CartItem $item) => $item->issue() === null)
            ->reduce(fn (string $sum, CartItem $item) => Money::add($sum, $amountOf($item)), Money::normalize('0'));
    }

    public function hasIssues(): bool
    {
        return $this->items->contains(fn (CartItem $item) => $item->issue() !== null);
    }
}
