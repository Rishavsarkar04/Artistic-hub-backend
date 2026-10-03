<?php

namespace Tests\Feature\Shop;

use App\Models\Media;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductVariantDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.media_disk' => 'media-test']);
        Storage::fake('media-test');
    }

    private function url(string $referenceId): string
    {
        return "/api/v1/shop/product-variants/{$referenceId}";
    }

    private function variant(array $attributes = [], ?Product $product = null): ProductVariant
    {
        return ProductVariant::factory()->for($product ?? Product::factory()->create())->create($attributes);
    }

    public function test_anyone_can_view_a_variant_with_its_product_photos_and_tags(): void
    {
        $product = Product::factory()->create(['name' => 'Amber & Sandalwood', 'description' => 'Warm and woody.']);
        $variant = $this->variant(['name' => 'Small', 'sku' => 'AS-S', 'original_price' => '1199.00', 'selling_price' => '899.00', 'stock' => 4], $product);
        $second = Media::factory()->attachedTo($variant, 1)->create();
        $cover = Media::factory()->attachedTo($variant, 0)->create();
        $variant->tags()->attach(Tag::factory()->create(['name' => 'Woody', 'slug' => 'woody']));

        $this->getJson($this->url($variant->reference_id))
            ->assertOk()
            ->assertJsonPath('data.reference_id', $variant->reference_id)
            ->assertJsonPath('data.name', 'Small')
            ->assertJsonPath('data.sku', 'AS-S')
            ->assertJsonPath('data.original_price', '1199.00')
            ->assertJsonPath('data.selling_price', '899.00')
            ->assertJsonPath('data.currency', 'INR')
            ->assertJsonPath('data.currency_symbol', '₹')
            ->assertJsonPath('data.stock', 4)
            ->assertJsonPath('data.in_stock', true)
            ->assertJsonPath('data.product.name', 'Amber & Sandalwood')
            ->assertJsonPath('data.photos.0.url', $cover->url())
            ->assertJsonPath('data.photos.1.url', $second->url())
            ->assertJsonPath('data.tags.0.slug', 'woody');
    }

    public function test_description_falls_back_to_the_product(): void
    {
        $product = Product::factory()->create(['description' => 'From the product.']);
        $own = $this->variant(['description' => 'From the variant.'], $product);
        $inherits = $this->variant(['description' => null], $product);

        $this->getJson($this->url($own->reference_id))->assertJsonPath('data.description', 'From the variant.');
        $this->getJson($this->url($inherits->reference_id))->assertJsonPath('data.description', 'From the product.');
    }

    public function test_an_out_of_stock_variant_still_opens(): void
    {
        $variant = $this->variant(['stock' => 0]);

        $this->getJson($this->url($variant->reference_id))
            ->assertOk()
            ->assertJsonPath('data.stock', 0)
            ->assertJsonPath('data.in_stock', false);
    }

    public function test_an_inactive_variant_or_product_or_unknown_id_is_not_found(): void
    {
        $inactive = $this->variant(['is_active' => false]);
        $ofInactiveProduct = $this->variant([], Product::factory()->inactive()->create());

        $this->getJson($this->url($inactive->reference_id))->assertNotFound();
        $this->getJson($this->url($ofInactiveProduct->reference_id))->assertNotFound();
        $this->getJson($this->url(strtolower((string) Str::ulid())))->assertNotFound();
        $this->getJson($this->url('not-a-ulid'))->assertNotFound();
    }

    public function test_other_variants_lists_only_the_products_other_buyable_variants(): void
    {
        $product = Product::factory()->create();
        $viewed = $this->variant([], $product);
        $sibling = $this->variant([], $product);
        $this->variant(['stock' => 0], $product);
        $this->variant(['is_active' => false], $product);
        $this->variant();

        $this->getJson($this->url($viewed->reference_id))
            ->assertOk()
            ->assertJsonCount(1, 'other_variants')
            ->assertJsonPath('other_variants.0.reference_id', $sibling->reference_id);
    }
}
