<?php

namespace App\Data;

use App\Models\Cart;
use App\Models\CustomerAddress;

/** What the review page shows: the chosen address and the cart, both checked and ready to pay. */
final readonly class CheckoutReview
{
    public function __construct(
        public CustomerAddress $address,
        public Cart $cart,
    ) {}
}
