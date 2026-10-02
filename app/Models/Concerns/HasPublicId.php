<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * Gives the model a client-facing `public_id` (a ULID, filled automatically on create) used in every
 * API response, URL and request instead of the internal auto-increment `id`. The `id` stays the
 * primary key for foreign keys and joins but never leaves the backend.
 */
trait HasPublicId
{
    use HasUlids;

    /** Only `public_id` gets a ULID; the primary key stays an auto-increment integer. */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** Route model binding looks models up by `public_id`; a malformed value is a 404. */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
