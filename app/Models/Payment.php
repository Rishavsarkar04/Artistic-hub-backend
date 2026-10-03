<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One payment attempt for an order (a Razorpay payment link). Nothing is mass-assignable. */
class Payment extends Model
{
    /** @var list<string> Never sent to clients. */
    protected $hidden = ['gateway_response'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'gateway_response' => 'array',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Still payable: pending, with a link that has not expired. */
    public function isPayable(): bool
    {
        return $this->status === PaymentStatus::Pending
            && $this->payment_url !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
