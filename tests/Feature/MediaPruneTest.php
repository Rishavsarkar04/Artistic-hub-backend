<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaPruneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.media_disk' => 'media-test', 'filesystems.media_orphan_hours' => 24]);
        Storage::fake('media-test');
    }

    private function mediaWithFile(array $attributes): Media
    {
        $media = Media::factory()->create(['disk' => 'media-test', ...$attributes]);
        Storage::disk('media-test')->put($media->path, 'image bytes');

        return $media;
    }

    public function test_unattached_uploads_older_than_the_window_are_pruned_with_their_files(): void
    {
        $orphan = $this->mediaWithFile(['created_at' => now()->subHours(25)]);
        $recent = $this->mediaWithFile(['created_at' => now()->subHours(2)]);
        $attached = Media::factory()->attachedTo(ProductVariant::factory()->create())->create(['disk' => 'media-test', 'created_at' => now()->subDays(30)]);
        Storage::disk('media-test')->put($attached->path, 'image bytes');

        $this->artisan('model:prune', ['--model' => [Media::class]])->assertSuccessful();

        $this->assertModelMissing($orphan);
        Storage::disk('media-test')->assertMissing($orphan->path);
        $this->assertModelExists($recent);
        Storage::disk('media-test')->assertExists($recent->path);
        $this->assertModelExists($attached);
        Storage::disk('media-test')->assertExists($attached->path);
    }

    public function test_pruning_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('model:prune')->assertSuccessful();
    }
}
