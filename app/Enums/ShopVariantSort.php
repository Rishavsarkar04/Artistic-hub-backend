<?php

namespace App\Enums;

/** Sort orders for the shop's product-variant listing. */
enum ShopVariantSort: string
{
    case Newest = 'newest';
    /** Selling price, lowest first. */
    case PriceLow = 'price_low';
    /** Selling price, highest first. */
    case PriceHigh = 'price_high';
}
