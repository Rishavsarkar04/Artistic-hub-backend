<?php

namespace App\Enums;

/** Sort orders for the admin product list. */
enum ProductSort: string
{
    case Newest = 'newest';
    case Name = 'name';
    /** By the product's lowest variant selling price. */
    case PriceLow = 'price_low';
    /** By the product's highest variant selling price. */
    case PriceHigh = 'price_high';
    /** By the total stock of all its variants, lowest first. */
    case StockLow = 'stock_low';
}
