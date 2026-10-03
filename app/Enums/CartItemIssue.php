<?php

namespace App\Enums;

/** Why a cart item cannot be bought as it is. Items are kept, never removed silently. */
enum CartItemIssue: string
{
    /** The variant or its product was turned off. */
    case Unavailable = 'unavailable';
    case OutOfStock = 'out_of_stock';
    /** Fewer left than the quantity in the cart. */
    case NotEnoughStock = 'not_enough_stock';
}
