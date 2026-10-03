<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\Concerns\MakesRealUploads;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use MakesRealUploads, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.media_disk' => 'media-test']);
        Storage::fake('media-test');
        Passport::actingAs(User::factory()->admin()->create(), [Role::Admin->scope()]);
    }

    /** Uploads one real PNG and returns its public media id. */
    private function uploadPhoto(): string
    {
        return $this->post('/api/v1/admin/uploads/variant-photos', ['photos' => [$this->realUpload('candle.png', $this->pngBytes())]], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.0.reference_id');
    }

    private function media(string $referenceId): Media
    {
        return Media::where('reference_id', $referenceId)->firstOrFail();
    }

    private function pathOf(string $referenceId): string
    {
        return $this->media($referenceId)->path;
    }

    /** A valid ULID that matches nothing. */
    private function unknownId(): string
    {
        return strtolower((string) Str::ulid());
    }

    /** @return array<string, mixed> */
    private function variant(array $overrides = []): array
    {
        static $counter = 0;
        $counter++;

        return [
            'name' => "Size {$counter}",
            'sku' => "EB-WS-{$counter}",
            'original_price' => '999',
            'selling_price' => '899.5',
            'stock' => 12,
            'is_active' => true,
            'tag_ids' => [],
            'photo_ids' => [],
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function product(array $variants, array $overrides = []): array
    {
        return ['name' => 'Winter Spice', 'description' => 'Warm and cosy.', 'is_active' => true, 'variants' => $variants, ...$overrides];
    }

    private function createProduct(array $variants, array $overrides = []): array
    {
        return $this->postJson('/api/v1/admin/products', $this->product($variants, $overrides))->assertCreated()->json('data');
    }

    public function test_an_admin_creates_a_product_with_variants_tags_and_photos(): void
    {
        $tag = Tag::factory()->create(['name' => 'Woody']);
        [$cover, $second] = [$this->uploadPhoto(), $this->uploadPhoto()];

        $this->postJson('/api/v1/admin/products', $this->product([
            $this->variant(['name' => 'Small · 4 oz', 'tag_ids' => [$tag->reference_id], 'photo_ids' => [$cover, $second]]),
            $this->variant(['name' => 'Large · 12 oz', 'slug' => 'ignored-slug']),
        ]))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'winter-spice')
            ->assertJsonPath('data.variants.0.slug', 'winter-spice-small-4-oz')
            ->assertJsonPath('data.variants.0.original_price', '999.00')
            ->assertJsonPath('data.variants.0.selling_price', '899.50')
            ->assertJsonPath('data.variants.0.tags.0.name', 'Woody')
            ->assertJsonPath('data.variants.0.photos.0.reference_id', $cover)
            ->assertJsonPath('data.variants.0.photos.0.sort_order', 0)
            ->assertJsonPath('data.variants.0.photos.1.reference_id', $second)
            ->assertJsonPath('data.variants.1.slug', 'winter-spice-large-12-oz');

        $this->assertSame($cover, Product::sole()->variants->first()->photos->first()->reference_id);
        $this->assertStringStartsWith('variant-photos/', $this->pathOf($cover));
    }

    public function test_slugs_are_made_unique(): void
    {
        $this->createProduct([$this->variant()]);

        // A different name that makes the same slug.
        $this->postJson('/api/v1/admin/products', $this->product([$this->variant()], ['name' => 'Winter-Spice']))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'winter-spice-2');
    }

    public function test_slugs_are_generated_and_kept_when_renamed(): void
    {
        $product = $this->createProduct([$this->variant(['name' => 'Small'])], ['slug' => 'sent-but-ignored']);
        $this->assertSame('winter-spice', $product['slug']);
        $variant = $product['variants'][0];

        $this->putJson("/api/v1/admin/products/{$product['reference_id']}", $this->product([
            $this->variant(['reference_id' => $variant['reference_id'], 'sku' => $variant['sku'], 'name' => 'Mini']),
        ], ['name' => 'Winter Spice Deluxe']))
            ->assertOk()
            ->assertJsonPath('data.slug', 'winter-spice')
            ->assertJsonPath('data.variants.0.slug', 'winter-spice-small');
    }

    public function test_variant_names_are_unique_within_the_product_only(): void
    {
        $this->postJson('/api/v1/admin/products', $this->product([
            $this->variant(['name' => 'Small · 4 oz']),
            $this->variant(['name' => 'SMALL · 4 OZ']),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variants.0.name' => 'Two variants of this product have the same name.', 'variants.1.name']);

        $this->createProduct([$this->variant(['name' => 'Small · 4 oz'])]);
        $this->createProduct([$this->variant(['name' => 'Small · 4 oz'])], ['name' => 'Rose Garden']);
    }

    public function test_product_names_are_unique_ignoring_case(): void
    {
        $winter = $this->createProduct([$this->variant()]);
        $other = $this->createProduct([$this->variant()], ['name' => 'Rose Garden']);

        $this->postJson('/api/v1/admin/products', $this->product([$this->variant()], ['name' => 'WINTER SPICE']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => "There's already a product called WINTER SPICE."]);

        $this->putJson("/api/v1/admin/products/{$other['reference_id']}", $this->product([
            $this->variant(['reference_id' => $other['variants'][0]['reference_id'], 'sku' => $other['variants'][0]['sku']]),
        ], ['name' => 'Winter Spice']))->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->putJson("/api/v1/admin/products/{$winter['reference_id']}", $this->product([
            $this->variant(['reference_id' => $winter['variants'][0]['reference_id'], 'sku' => $winter['variants'][0]['sku']]),
        ]))->assertOk();
    }

    public function test_price_rules(): void
    {
        $this->postJson('/api/v1/admin/products', $this->product([
            $this->variant(['original_price' => '500', 'selling_price' => '500.01']),
            $this->variant(['original_price' => '0', 'selling_price' => '0']),
            $this->variant(['original_price' => '12.345']),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variants.0.selling_price', 'variants.1.original_price', 'variants.1.selling_price', 'variants.2.original_price']);
    }

    public function test_skus_must_be_unique_in_the_request_and_across_products(): void
    {
        $this->postJson('/api/v1/admin/products', $this->product([$this->variant(['sku' => 'DUP']), $this->variant(['sku' => 'DUP'])]))
            ->assertUnprocessable()->assertJsonValidationErrors('variants.0.sku');

        ProductVariant::factory()->create(['sku' => 'TAKEN']);
        $this->postJson('/api/v1/admin/products', $this->product([$this->variant(['sku' => 'TAKEN'])]))
            ->assertUnprocessable()->assertJsonValidationErrors('variants.0.sku');
    }

    public function test_photos_must_be_uploaded_through_the_upload_endpoint(): void
    {
        $avatar = Media::factory()->avatar()->create();

        $this->postJson('/api/v1/admin/products', $this->product([$this->variant(['photo_ids' => [$this->unknownId(), $avatar->reference_id]])]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'variants.0.photo_ids.0' => 'This photo was not found. Upload it again.',
                'variants.0.photo_ids.1',
            ]);
    }

    public function test_saving_updates_creates_and_deletes_variants_and_cleans_up_photo_files(): void
    {
        [$keep, $drop, $gone] = [$this->uploadPhoto(), $this->uploadPhoto(), $this->uploadPhoto()];
        [$keepPath, $dropPath, $gonePath] = [$this->pathOf($keep), $this->pathOf($drop), $this->pathOf($gone)];
        $product = $this->createProduct([
            $this->variant(['photo_ids' => [$keep, $drop]]),
            $this->variant(['photo_ids' => [$gone]]),
        ]);
        [$first, $second] = $product['variants'];
        $new = $this->uploadPhoto();
        $newPath = $this->pathOf($new);

        $this->putJson("/api/v1/admin/products/{$product['reference_id']}", $this->product([
            $this->variant(['reference_id' => $first['reference_id'], 'sku' => $first['sku'], 'name' => 'Renamed', 'photo_ids' => [$new, $keep]]),
            $this->variant(['name' => 'Brand new']),
        ]))
            ->assertOk()
            ->assertJsonCount(2, 'data.variants')
            ->assertJsonPath('data.variants.0.name', 'Renamed')
            ->assertJsonPath('data.variants.0.slug', $first['slug'])
            ->assertJsonPath('data.variants.0.photos.0.reference_id', $new)
            ->assertJsonPath('data.variants.0.photos.1.reference_id', $keep)
            ->assertJsonPath('data.variants.1.name', 'Brand new');

        $this->assertDatabaseMissing('product_variants', ['reference_id' => $second['reference_id']]);
        Storage::disk('media-test')->assertExists([$keepPath, $newPath]);
        // Dropped from photo_ids: deleted now, with its file.
        Storage::disk('media-test')->assertMissing($dropPath);
        $this->assertDatabaseMissing('media', ['reference_id' => $drop]);
        // On a variant that was deleted: detached (owner null), file kept until the daily prune.
        $this->assertDetached($gone);
        Storage::disk('media-test')->assertExists($gonePath);
    }

    public function test_a_photo_can_move_between_variants_of_the_product(): void
    {
        $photo = $this->uploadPhoto();
        $product = $this->createProduct([$this->variant(['photo_ids' => [$photo]]), $this->variant()]);
        [$from, $to] = $product['variants'];

        $this->putJson("/api/v1/admin/products/{$product['reference_id']}", $this->product([
            $this->variant(['reference_id' => $from['reference_id'], 'sku' => $from['sku']]),
            $this->variant(['reference_id' => $to['reference_id'], 'sku' => $to['sku'], 'photo_ids' => [$photo]]),
        ]))->assertOk()->assertJsonPath('data.variants.1.photos.0.reference_id', $photo);

        Storage::disk('media-test')->assertExists($this->pathOf($photo));
        $this->assertSame(ProductVariant::where('reference_id', $to['reference_id'])->value('id'), $this->media($photo)->mediable_id);
    }

    public function test_an_inactive_product_makes_every_variant_inactive(): void
    {
        $this->postJson('/api/v1/admin/products', $this->product([$this->variant(['is_active' => true])], ['is_active' => false]))
            ->assertCreated()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.variants.0.is_active', false);
    }

    public function test_another_products_variant_or_photo_cannot_be_taken(): void
    {
        $photo = $this->uploadPhoto();
        $other = $this->createProduct([$this->variant(['photo_ids' => [$photo]])], ['name' => 'Other']);
        $mine = $this->createProduct([$this->variant()], ['name' => 'Mine']);

        $this->putJson("/api/v1/admin/products/{$mine['reference_id']}", $this->product([
            $this->variant(['reference_id' => $other['variants'][0]['reference_id']]),
        ], ['name' => 'Mine']))->assertUnprocessable()->assertJsonValidationErrors('variants.0.reference_id');

        $this->putJson("/api/v1/admin/products/{$mine['reference_id']}", $this->product([
            $this->variant(['reference_id' => $mine['variants'][0]['reference_id'], 'sku' => $mine['variants'][0]['sku'], 'photo_ids' => [$photo]]),
        ], ['name' => 'Mine']))->assertUnprocessable()->assertJsonValidationErrors('variants.0.photo_ids');
    }

    public function test_deleting_a_product_deletes_variants_and_tag_links_and_detaches_photos(): void
    {
        $tag = Tag::factory()->create();
        $photo = $this->uploadPhoto();
        $photoPath = $this->pathOf($photo);
        $product = $this->createProduct([$this->variant(['tag_ids' => [$tag->reference_id], 'photo_ids' => [$photo]])]);

        $this->deleteJson("/api/v1/admin/products/{$product['reference_id']}")->assertNoContent();

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_variants', 0);
        $this->assertDatabaseCount('product_variant_tags', 0);
        $this->assertModelExists($tag);
        $this->assertDetached($photo);
        Storage::disk('media-test')->assertExists($photoPath);
    }

    public function test_photos_of_a_deleted_product_are_removed_by_the_daily_prune(): void
    {
        $photo = $this->uploadPhoto();
        $photoPath = $this->pathOf($photo);
        $product = $this->createProduct([$this->variant(['photo_ids' => [$photo]])]);
        $this->deleteJson("/api/v1/admin/products/{$product['reference_id']}")->assertNoContent();

        $this->travel(25)->hours();
        $this->artisan('model:prune', ['--model' => [Media::class]])->assertSuccessful();

        $this->assertDatabaseMissing('media', ['reference_id' => $photo]);
        Storage::disk('media-test')->assertMissing($photoPath);
    }

    private function assertDetached(string $referenceId): void
    {
        $media = $this->media($referenceId);
        $this->assertNull($media->mediable_type);
        $this->assertNull($media->mediable_id);
    }

    public function test_deleting_one_variant(): void
    {
        $photo = $this->uploadPhoto();
        $photoPath = $this->pathOf($photo);
        $product = $this->createProduct([$this->variant(['photo_ids' => [$photo]]), $this->variant()]);
        $other = $this->createProduct([$this->variant()], ['name' => 'Other']);
        [$first, $second] = $product['variants'];

        $this->deleteJson("/api/v1/admin/products/{$product['reference_id']}/variants/{$other['variants'][0]['reference_id']}")->assertNotFound();
        $this->deleteJson("/api/v1/admin/products/{$product['reference_id']}/variants/{$first['reference_id']}")->assertNoContent();
        $this->assertDetached($photo);
        Storage::disk('media-test')->assertExists($photoPath);

        $this->deleteJson("/api/v1/admin/products/{$product['reference_id']}/variants/{$second['reference_id']}")
            ->assertConflict()
            ->assertJsonPath('message', 'A product needs at least one variant. Delete the product instead.');
    }

    public function test_listing_searches_filters_and_echoes_the_filters(): void
    {
        $this->createProduct([$this->variant(['sku' => 'FIND-ME'])], ['name' => 'Rose Garden']);
        $this->createProduct([$this->variant()], ['name' => 'Sleeping Owl', 'is_active' => false]);

        $this->getJson('/api/v1/admin/products?search=find-me')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Rose Garden')
            ->assertJsonPath('filters.search', 'find-me');

        $this->getJson('/api/v1/admin/products?status=inactive')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Sleeping Owl')
            ->assertJsonPath('filter_options.status', ['active', 'inactive']);
    }

    public function test_sorting_by_price_and_stock(): void
    {
        $cheap = $this->createProduct([$this->variant(['original_price' => '300', 'selling_price' => '250', 'stock' => 50])], ['name' => 'Cheap']);
        $wide = $this->createProduct([
            $this->variant(['original_price' => '200', 'selling_price' => '200', 'stock' => 1]),
            $this->variant(['original_price' => '2000', 'selling_price' => '1500', 'stock' => 1]),
        ], ['name' => 'Wide range']);
        $mid = $this->createProduct([$this->variant(['original_price' => '800', 'selling_price' => '700', 'stock' => 5])], ['name' => 'Mid']);

        $ids = fn (string $sort) => array_column($this->getJson("/api/v1/admin/products?sort={$sort}")->assertOk()->json('data'), 'reference_id');

        $this->assertSame([$wide['reference_id'], $cheap['reference_id'], $mid['reference_id']], $ids('price_low'));   // lowest prices 200, 250, 700
        $this->assertSame([$wide['reference_id'], $mid['reference_id'], $cheap['reference_id']], $ids('price_high'));  // highest prices 1500, 700, 250
        $this->assertSame([$wide['reference_id'], $mid['reference_id'], $cheap['reference_id']], $ids('stock_low'));   // total stock 2, 5, 50
        $this->assertSame([$cheap['reference_id'], $mid['reference_id'], $wide['reference_id']], $ids('name'));

        $this->getJson('/api/v1/admin/products')
            ->assertJsonPath('filter_options.sort', ['newest', 'name', 'price_low', 'price_high', 'stock_low']);
        $this->getJson('/api/v1/admin/products?sort=oldest')->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_several_photos_upload_in_one_request_in_order(): void
    {
        $response = $this->post('/api/v1/admin/uploads/variant-photos', ['photos' => [
            $this->realUpload('front.png', $this->pngBytes()),
            $this->realUpload('side.png', $this->pngBytes()),
            $this->realUpload('back.png', $this->pngBytes()),
        ]], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonCount(3, 'data');

        $ids = array_column($response->json('data'), 'reference_id');
        $this->assertSame($ids, Media::orderBy('id')->pluck('reference_id')->all());
        $this->assertSame(3, Media::whereNull('mediable_id')->count());
        $this->assertCount(3, Storage::disk('media-test')->allFiles('variant-photos'));
    }

    public function test_one_bad_file_rejects_the_whole_batch(): void
    {
        $this->post('/api/v1/admin/uploads/variant-photos', ['photos' => [
            $this->realUpload('front.png', $this->pngBytes()),
            $this->realUpload('notes.pdf', "%PDF-1.4\n%fake\n"),
        ]], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photos.1');

        $this->assertDatabaseCount('media', 0);
        $this->assertSame([], Storage::disk('media-test')->allFiles());
    }

    public function test_deleting_your_own_unsaved_upload(): void
    {
        $photo = $this->uploadPhoto();
        $path = $this->pathOf($photo);

        $this->deleteJson("/api/v1/admin/uploads/variant-photos/{$photo}")->assertNoContent();

        $this->assertDatabaseMissing('media', ['reference_id' => $photo]);
        Storage::disk('media-test')->assertMissing($path);
    }

    public function test_deleting_a_photo_of_a_saved_variant_keeps_the_others(): void
    {
        [$cover, $second, $third] = [$this->uploadPhoto(), $this->uploadPhoto(), $this->uploadPhoto()];
        $secondPath = $this->pathOf($second);
        $product = $this->createProduct([$this->variant(['photo_ids' => [$cover, $second, $third]])]);

        $this->deleteJson("/api/v1/admin/uploads/variant-photos/{$second}")->assertNoContent();

        Storage::disk('media-test')->assertMissing($secondPath);
        $this->getJson("/api/v1/admin/products/{$product['reference_id']}")
            ->assertJsonCount(2, 'data.variants.0.photos')
            ->assertJsonPath('data.variants.0.photos.0.reference_id', $cover)
            ->assertJsonPath('data.variants.0.photos.1.reference_id', $third);
    }

    public function test_any_admin_can_delete_another_admins_unsaved_upload(): void
    {
        $theirs = Media::factory()->create(['uploaded_by' => User::factory()->admin()->create()->id]);

        $this->deleteJson("/api/v1/admin/uploads/variant-photos/{$theirs->reference_id}")->assertNoContent();

        $this->assertModelMissing($theirs);
    }

    public function test_only_variant_photos_can_be_deleted_here(): void
    {
        $avatar = Media::factory()->avatar()->create();

        $this->deleteJson("/api/v1/admin/uploads/variant-photos/{$avatar->reference_id}")->assertNotFound();
        $this->deleteJson("/api/v1/admin/uploads/variant-photos/{$this->unknownId()}")->assertNotFound();
        $this->assertModelExists($avatar);
    }

    public function test_a_batch_has_one_to_eight_photos(): void
    {
        $nine = array_map(fn () => $this->realUpload('p.png', $this->pngBytes()), range(1, 9));

        $this->post('/api/v1/admin/uploads/variant-photos', ['photos' => $nine], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('photos');
        $this->post('/api/v1/admin/uploads/variant-photos', ['photos' => []], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('photos');
    }

    public function test_customers_cannot_use_the_catalog_admin(): void
    {
        Passport::actingAs(User::factory()->customer()->create(), [Role::Customer->scope()]);

        $this->getJson('/api/v1/admin/products')->assertForbidden();
        $this->postJson('/api/v1/admin/tags', ['name' => 'Hack'])->assertForbidden();
        $this->deleteJson("/api/v1/admin/uploads/variant-photos/{$this->unknownId()}")->assertForbidden();
    }
}
