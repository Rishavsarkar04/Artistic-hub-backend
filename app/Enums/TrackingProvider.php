<?php

namespace App\Enums;

/**
 * Couriers an admin can pick for an order's tracking (orders.tracking_provider). A fixed list: add a courier
 * here (with its label) to offer it in the dropdown.
 */
enum TrackingProvider: string
{
    case Delhivery = 'delhivery';
    case BlueDart = 'bluedart';
    case Dtdc = 'dtdc';
    case IndiaPost = 'india_post';
    case Ekart = 'ekart';
    case Xpressbees = 'xpressbees';
    case Shadowfax = 'shadowfax';
    case EcomExpress = 'ecom_express';

    public function label(): string
    {
        return match ($this) {
            self::Delhivery => 'Delhivery',
            self::BlueDart => 'Blue Dart',
            self::Dtdc => 'DTDC',
            self::IndiaPost => 'India Post',
            self::Ekart => 'Ekart',
            self::Xpressbees => 'Xpressbees',
            self::Shadowfax => 'Shadowfax',
            self::EcomExpress => 'Ecom Express',
        };
    }

    /**
     * The dropdown's options, in this order.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $provider) => ['value' => $provider->value, 'label' => $provider->label()], self::cases());
    }
}
