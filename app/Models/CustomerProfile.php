<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\MediaCollection;
use Database\Factories\CustomerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer's profile, created after their first sign-in. The customer's name lives on `users.name`.
 * `user_id` and the internal `notes` are not fillable. The avatar is a Media row (avatar()).
 * Soft-deleted and restored together with its user. Its addresses are only reached through the
 * profile, so they are hidden with it. Records that must survive (orders) load it withTrashed().
 */
#[Fillable(['phone', 'date_of_birth', 'gender'])]
#[Hidden(['notes'])]
class CustomerProfile extends Model
{
    /** @use HasFactory<CustomerProfileFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'gender' => Gender::class,
            'deleted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CustomerAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /** @return MorphOne<Media, $this> */
    public function avatar(): MorphOne
    {
        return $this->morphOne(Media::class, 'mediable')->where('collection', MediaCollection::Avatar);
    }

    /** @return HasOne<Cart, $this> Null until the first add. */
    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    /** @return HasOne<CustomerAddress, $this> */
    public function defaultAddress(): HasOne
    {
        return $this->hasOne(CustomerAddress::class)->where('is_default', true);
    }
}
