<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerProfile;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer's server cart. Every lookup goes through the customer's own profile, so another customer's
 * item is simply "not found". Each change locks the profile row first (which also covers creating the
 * cart on the first add), so two adds of the same variant end up as one item. Items are never removed
 * silently: one that can no longer be bought stays, flagged by CartItem::issue().
 */
final class CartService
{
    /** The customer's cart with everything a cart response shows; an empty, unsaved cart before the first add. */
    public function getCart(CustomerProfile $profile): Cart
    {
        $cart = $profile->cart()->first();

        if ($cart === null) {
            $cart = new Cart;
            $cart->setRelation('items', $cart->newCollection());

            return $cart;
        }

        return $cart->load(['items.productVariant.product', 'items.productVariant.photos']);
    }

    /**
     * Adds a buyable variant, or increases its quantity when it is already in the cart.
     *
     * @param  string  $productVariantId  the variant's reference_id
     *
     * @throws ValidationException when the variant cannot be bought or the new quantity is more than the stock
     */
    public function addItem(CustomerProfile $profile, string $productVariantId, int $quantity): Cart
    {
        DB::transaction(function () use ($profile, $productVariantId, $quantity) {
            $this->lockProfile($profile);

            $variant = ProductVariant::query()->buyable()->where('product_variants.reference_id', $productVariantId)->first()
                ?? throw ValidationException::withMessages(['product_variant_id' => 'This item is not available.']);

            $cart = $profile->cart()->firstOrCreate();
            $item = $cart->items()->firstOrNew(['product_variant_id' => $variant->id]);
            $newQuantity = ($item->exists ? $item->quantity : 0) + $quantity;

            if ($newQuantity > $variant->stock) {
                throw ValidationException::withMessages(['quantity' => $item->exists
                    ? "Only {$variant->stock} left, and {$item->quantity} already in your cart."
                    : "Only {$variant->stock} left."]);
            }

            $item->quantity = $newQuantity;
            $item->save();
        });

        return $this->getCart($profile);
    }

    /**
     * Sets an item's quantity. Lowering it always works; raising it needs the variant to be buyable
     * with enough stock.
     *
     * @param  string  $itemId  the cart item's reference_id
     *
     * @throws ModelNotFoundException when the item is not in this customer's cart
     * @throws ValidationException when raising the quantity past what can be bought
     */
    public function updateItemQuantity(CustomerProfile $profile, string $itemId, int $quantity): Cart
    {
        DB::transaction(function () use ($profile, $itemId, $quantity) {
            $this->lockProfile($profile);

            $item = $this->findItem($profile, $itemId);

            if ($quantity > $item->quantity) {
                $variant = $item->productVariant()->buyable()->first()
                    ?? throw ValidationException::withMessages(['quantity' => 'This item is not available.']);

                if ($quantity > $variant->stock) {
                    throw ValidationException::withMessages(['quantity' => "Only {$variant->stock} left."]);
                }
            }

            $item->quantity = $quantity;
            $item->save();
        });

        return $this->getCart($profile);
    }

    /**
     * @param  string  $itemId  the cart item's reference_id
     *
     * @throws ModelNotFoundException when the item is not in this customer's cart
     */
    public function removeItem(CustomerProfile $profile, string $itemId): Cart
    {
        DB::transaction(function () use ($profile, $itemId) {
            $this->lockProfile($profile);
            $this->findItem($profile, $itemId)->delete();
        });

        return $this->getCart($profile);
    }

    private function findItem(CustomerProfile $profile, string $itemId): CartItem
    {
        return CartItem::query()
            ->whereRelation('cart', 'customer_profile_id', $profile->getKey())
            ->where('reference_id', $itemId)
            ->firstOrFail();
    }

    private function lockProfile(CustomerProfile $profile): void
    {
        CustomerProfile::whereKey($profile->getKey())->lockForUpdate()->first();
    }
}
