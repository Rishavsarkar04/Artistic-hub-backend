<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\ProductVariant;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class TagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Passport::actingAs(User::factory()->admin()->create(), [Role::Admin->scope()]);
    }

    public function test_an_admin_lists_and_creates_tags(): void
    {
        Tag::factory()->create(['name' => 'Woody', 'slug' => 'woody']);

        $this->postJson('/api/v1/admin/tags', ['name' => '  Best seller '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Best seller')
            ->assertJsonPath('data.slug', 'best-seller');

        $this->getJson('/api/v1/admin/tags')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Best seller')
            ->assertJsonPath('data.1.name', 'Woody');
    }

    public function test_names_are_unique_ignoring_case_and_punctuation(): void
    {
        Tag::factory()->create(['name' => 'Best seller', 'slug' => 'best-seller']);

        $this->postJson('/api/v1/admin/tags', ['name' => 'BEST SELLER'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/admin/tags', ['name' => 'Best-seller'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/admin/tags', ['name' => '!!!'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_an_exact_duplicate_name_is_rejected_but_a_tag_can_keep_its_own_name(): void
    {
        $woody = Tag::factory()->create(['name' => 'Woody', 'slug' => 'woody']);
        $floral = Tag::factory()->create(['name' => 'Floral', 'slug' => 'floral']);

        $this->postJson('/api/v1/admin/tags', ['name' => 'Woody'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => "There's already a tag called Woody."]);
        $this->putJson("/api/v1/admin/tags/{$floral->reference_id}", ['name' => 'Woody'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson("/api/v1/admin/tags/{$woody->reference_id}", ['name' => 'Woody'])->assertOk();
    }

    public function test_renaming_updates_the_name_and_slug(): void
    {
        $tag = Tag::factory()->create(['name' => 'Woodsy', 'slug' => 'woodsy']);

        $this->putJson("/api/v1/admin/tags/{$tag->reference_id}", ['name' => 'Woody'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'woody');

        $this->putJson("/api/v1/admin/tags/{$tag->reference_id}", ['name' => 'woody'])->assertOk();
    }

    public function test_deleting_a_tag_keeps_the_variants(): void
    {
        $tag = Tag::factory()->create();
        $variant = ProductVariant::factory()->create();
        $variant->tags()->attach($tag);

        $this->deleteJson("/api/v1/admin/tags/{$tag->reference_id}")->assertNoContent();

        $this->assertModelMissing($tag);
        $this->assertModelExists($variant);
        $this->assertDatabaseCount('product_variant_tags', 0);
    }
}
