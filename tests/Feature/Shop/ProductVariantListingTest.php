<?php

namespace Tests\Feature\Shop;

use App\Models\Media;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductVariantListingTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/shop/product-variants';

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.media_disk' => 'media-test']);
        Storage::fake('media-test');
    }

    private function variant(array $attributes = [], ?Product $product = null): ProductVariant
    {
        return ProductVariant::factory()->for($product ?? Product::factory()->create())->create($attributes);
    }

    /** @return list<string> reference ids in response order */
    private function listed(string $query = ''): array
    {
        return array_column($this->getJson(self::URL.$query)->assertOk()->json('data'), 'reference_id');
    }

    public function test_anyone_can_list_and_only_buyable_variants_appear(): void
    {
        $buyable = $this->variant(['stock' => 3]);
        $this->variant(['stock' => 0]);
        $this->variant(['is_active' => false]);
        $this->variant([], Product::factory()->inactive()->create());

        $this->assertSame([$buyable->reference_id], $this->listed());
    }

    public function test_a_card_has_product_prices_cover_and_tags(): void
    {
        $product = Product::factory()->create(['name' => 'Amber & Sandalwood']);
        $variant = $this->variant(['name' => 'Small', 'original_price' => '1199.00', 'selling_price' => '899.00'], $product);
        $cover = Media::factory()->attachedTo($variant, 0)->create();
        $hover = Media::factory()->attachedTo($variant, 1)->create();
        $variant->tags()->attach(Tag::factory()->create(['name' => 'Woody', 'slug' => 'woody']));

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.0.reference_id', $variant->reference_id)
            ->assertJsonPath('data.0.product.name', 'Amber & Sandalwood')
            ->assertJsonPath('data.0.original_price', '1199.00')
            ->assertJsonPath('data.0.selling_price', '899.00')
            ->assertJsonPath('data.0.currency', 'INR')
            ->assertJsonPath('data.0.currency_symbol', '₹')
            ->assertJsonPath('data.0.cover_url', $cover->url())
            ->assertJsonPath('data.0.hover_url', $hover->url())
            ->assertJsonPath('data.0.tags.0.slug', 'woody')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_hover_url_is_null_with_a_single_photo(): void
    {
        $variant = $this->variant();
        $cover = Media::factory()->attachedTo($variant, 0)->create();

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.0.cover_url', $cover->url())
            ->assertJsonPath('data.0.hover_url', null);
    }

    public function test_search_matches_names_descriptions_and_tags(): void
    {
        $byProduct = $this->variant([], Product::factory()->create(['name' => 'French Lavender']));
        $byDescription = $this->variant(['description' => 'Notes of sandalwood and vanilla']);
        $byTag = $this->variant();
        $byTag->tags()->attach(Tag::factory()->create(['name' => 'Smoky', 'slug' => 'smoky']));
        $this->variant([], Product::factory()->create(['name' => 'Unrelated']));

        $this->assertSame([$byProduct->reference_id], $this->listed('?search=lavender'));
        $this->assertSame([$byDescription->reference_id], $this->listed('?search=sandalwood'));
        $this->assertSame([$byTag->reference_id], $this->listed('?search=smok'));
    }

    public function test_price_range_and_tags_filter_together(): void
    {
        $woody = Tag::factory()->create(['slug' => 'woody']);
        $floral = Tag::factory()->create(['slug' => 'floral']);
        $cheapWoody = $this->variant(['selling_price' => '500.00', 'original_price' => '500.00']);
        $midWoody = $this->variant(['selling_price' => '900.00', 'original_price' => '900.00']);
        $midFloral = $this->variant(['selling_price' => '950.00', 'original_price' => '950.00']);
        $pricey = $this->variant(['selling_price' => '3000.00', 'original_price' => '3000.00']);
        $cheapWoody->tags()->attach($woody);
        $midWoody->tags()->attach($woody);
        $midFloral->tags()->attach($floral);

        $this->assertEqualsCanonicalizing([$midWoody->reference_id, $midFloral->reference_id, $pricey->reference_id], $this->listed('?min_price=800'));
        $this->assertEqualsCanonicalizing([$midWoody->reference_id, $midFloral->reference_id], $this->listed('?min_price=800&max_price=1000'));
        $this->assertSame([$midWoody->reference_id], $this->listed('?min_price=800&max_price=1000&tags[]=woody'));
        $this->assertEqualsCanonicalizing([$cheapWoody->reference_id, $midWoody->reference_id, $midFloral->reference_id], $this->listed('?tags[]=woody&tags[]=floral'));
    }

    public function test_sorting(): void
    {
        $older = $this->variant(['selling_price' => '700.00', 'original_price' => '700.00', 'created_at' => Carbon::parse('2026-01-01')]);
        $newer = $this->variant(['selling_price' => '1500.00', 'original_price' => '1500.00', 'created_at' => Carbon::parse('2026-06-01')]);
        $middle = $this->variant(['selling_price' => '900.00', 'original_price' => '900.00', 'created_at' => Carbon::parse('2026-03-01')]);

        $this->assertSame([$newer->reference_id, $middle->reference_id, $older->reference_id], $this->listed());
        $this->assertSame([$older->reference_id, $middle->reference_id, $newer->reference_id], $this->listed('?sort=price_low'));
        $this->assertSame([$newer->reference_id, $middle->reference_id, $older->reference_id], $this->listed('?sort=price_high'));
    }

    public function test_price_range_tag_counts_and_filters_are_returned(): void
    {
        $woody = Tag::factory()->create(['name' => 'Woody', 'slug' => 'woody']);
        $floral = Tag::factory()->create(['name' => 'Floral', 'slug' => 'floral']);
        $a = $this->variant(['selling_price' => '800.00', 'original_price' => '800.00']);
        $b = $this->variant(['selling_price' => '3200.00', 'original_price' => '3200.00']);
        $soldOut = $this->variant(['stock' => 0, 'selling_price' => '50.00', 'original_price' => '50.00']);
        $a->tags()->attach([$woody->id, $floral->id]);
        $b->tags()->attach($woody);
        $soldOut->tags()->attach($floral);

        $expectedCounts = [
            ['slug' => 'floral', 'name' => 'Floral', 'count' => 1],
            ['slug' => 'woody', 'name' => 'Woody', 'count' => 2],
        ];

        // Counts ignore every filter, and the sold-out variant.
        $this->getJson(self::URL.'?search=nothing-matches&max_price=100')
            ->assertOk()
            ->assertJsonPath('tag_counts', $expectedCounts);

        $this->getJson(self::URL.'?tags[]=floral')
            ->assertOk()
            ->assertJsonPath('price_range', ['min' => '800.00', 'max' => '3200.00'])
            ->assertJsonPath('tag_counts', $expectedCounts)
            ->assertJsonPath('filters.tags', ['floral'])
            ->assertJsonPath('filters.sort', 'newest')
            ->assertJsonPath('filter_options.sort', ['newest', 'price_low', 'price_high']);
    }

    public function test_pagination(): void
    {
        foreach (range(1, 5) as $n) {
            $this->variant();
        }

        $this->getJson(self::URL.'?per_page=2&page=3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->getJson(self::URL.'?min_price=abc&sort=featured&per_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['min_price', 'sort', 'per_page']);

        $this->getJson(self::URL.'?min_price=1000&max_price=500')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['max_price' => 'The maximum price cannot be below the minimum price.']);
    }
}
