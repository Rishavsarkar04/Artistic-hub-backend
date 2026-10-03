<?php

namespace App\Models;

use App\Enums\CartItemIssue;
use App\Models\Concerns\HasReferenceId;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One variant and its quantity in a cart. Changed only through CartService; `cart_id` is not fillable. */
#[Fillable(['product_variant_id', 'quantity'])]
class CartItem extends Model
{
    use HasReferenceId;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** Current selling price × quantity (before any discount, like order_items.subtotal). */
    public function subtotal(): string
    {
        return Money::multiply($this->productVariant->selling_price, $this->quantity);
    }

    /** Current original price (MRP) × quantity, for showing the struck-through line price. */
    public function originalSubtotal(): string
    {
        return Money::multiply($this->productVariant->original_price, $this->quantity);
    }

    /** Why this item cannot be bought as it is, or null when it can. */
    public function issue(): ?CartItemIssue
    {
        $variant = $this->productVariant;

        return match (true) {
            ! $variant->is_active || ! $variant->product->is_active => CartItemIssue::Unavailable,
            $variant->stock <= 0 => CartItemIssue::OutOfStock,
            $this->quantity > $variant->stock => CartItemIssue::NotEnoughStock,
            default => null,
        };
    }
}
