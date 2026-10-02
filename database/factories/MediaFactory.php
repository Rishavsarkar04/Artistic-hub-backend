<?php

namespace Database\Factories;

use App\Enums\MediaCollection;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/** @extends Factory<Media> */
class MediaFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'collection' => MediaCollection::VariantPhoto,
            'disk' => config('filesystems.media_disk'),
            'path' => MediaCollection::VariantPhoto->directory().'/'.strtolower((string) str()->ulid()).'.png',
            'mime_type' => 'image/png',
            'size' => 1024,
            'uploaded_by' => null,
            'mediable_type' => null,
            'mediable_id' => null,
            'sort_order' => 0,
        ];
    }

    public function avatar(): static
    {
        return $this->state(['collection' => MediaCollection::Avatar, 'path' => 'avatars/'.strtolower((string) str()->ulid()).'.png']);
    }

    public function attachedTo(Model $owner, int $sortOrder = 0): static
    {
        return $this->state(['mediable_type' => $owner->getMorphClass(), 'mediable_id' => $owner->getKey(), 'sort_order' => $sortOrder]);
    }
}
