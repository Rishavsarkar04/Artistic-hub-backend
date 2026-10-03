<?php

namespace App\Enums;

/** payments.method, as reported by the payment provider once the customer pays. */
enum PaymentMethod: string
{
    case Card = 'card';
    case Upi = 'upi';
    case NetBanking = 'netbanking';
    case Wallet = 'wallet';
    case Emi = 'emi';
}
