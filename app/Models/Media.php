<?php

namespace App\Models;

use App\Enums\MediaCollection;
use App\Models\Concerns\HasPublicId;
use App\Services\Media\MediaStorageService;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An uploaded file. It is created unattached when the file is uploaded, then attached to its owner
 * (mediable: a product variant, a customer profile) when the owner is saved. Unattached media older
 * than config('filesystems.media_orphan_hours') is pruned with its file by `php artisan model:prune`.
 * No field is mass-assignable: MediaService sets everything.
 */
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory, HasPublicId, Prunable;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'collection' => MediaCollection::class,
            'size' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): string
    {
        return app(MediaStorageService::class)->url($this->path, $this->disk);
    }

    public function isAttached(): bool
    {
        return $this->mediable_id !== null;
    }

    /** Uploads never attached to an owner, past the retention window. */
    public function prunable(): Builder
    {
        return static::query()
            ->whereNull('mediable_id')
            ->where('created_at', '<=', now()->subHours((int) config('filesystems.media_orphan_hours')));
    }

    /** Runs before each pruned row is deleted: remove its file too. */
    protected function pruning(): void
    {
        app(MediaStorageService::class)->delete($this->path, $this->disk);
    }
}
