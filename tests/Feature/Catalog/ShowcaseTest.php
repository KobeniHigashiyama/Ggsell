<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShowcaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
        $this->seedSupplierKeys('a', 'KEY-CS2-PRIME', 3);
    }

    #[Test]
    public function storefront_returns_stock_from_projection(): void
    {
        $response = $this->getJson('/api/v1/products?limit=50')->assertOk();

        $cs2 = collect($response->json('data'))->firstWhere('sku', 'KEY-CS2-PRIME');

        $this->assertSame(3, $cs2['available']);
        $this->assertTrue($cs2['in_stock']);

        $gta = collect($response->json('data'))->firstWhere('sku', 'KEY-GTA5');
        $this->assertSame(0, $gta['available']);
        $this->assertFalse($gta['in_stock']);
    }

    #[Test]
    public function keyset_pagination_traverses_catalog_without_gaps_or_duplicates(): void
    {
        $seen = [];
        $cursor = null;

        do {
            $url = '/api/v1/products?limit=5'.($cursor !== null ? '&cursor='.urlencode($cursor) : '');
            $page = $this->getJson($url)->assertOk();

            foreach ($page->json('data') as $item) {
                $seen[] = $item['sku'];
            }

            $cursor = $page->json('next_cursor');
        } while ($cursor !== null);

        $expected = DB::table('products')->where('is_active', true)->count();

        $this->assertCount($expected, $seen, 'Traversal must cover the entire catalog.');
        $this->assertSame($expected, count(array_unique($seen)), 'No SKU may appear twice.');
    }

    #[Test]
    public function type_and_availability_filters_work(): void
    {
        $this->getJson('/api/v1/products?type=giftcard&limit=50')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $inStock = $this->getJson('/api/v1/products?in_stock=1&limit=50')->assertOk();

        $this->assertSame(['KEY-CS2-PRIME'], array_column($inStock->json('data'), 'sku'));
    }

    #[Test]
    public function disabled_product_is_not_shown_on_storefront(): void
    {
        DB::table('products')->where('sku', 'KEY-CS2-PRIME')->update(['is_active' => false]);

        $skus = array_column($this->getJson('/api/v1/products?limit=50')->json('data'), 'sku');

        $this->assertNotContains('KEY-CS2-PRIME', $skus);
    }

    /**
     * Query strings provide "true" and "false", which Laravel's boolean rule does
     * not accept directly. The documented representation must not return 422.
     */
    #[Test]
    public function availability_filter_accepts_string_values(): void
    {
        foreach (['1', 'true', 'True'] as $value) {
            $skus = array_column(
                $this->getJson("/api/v1/products?in_stock={$value}&limit=50")->assertOk()->json('data'),
                'sku',
            );

            $this->assertSame(['KEY-CS2-PRIME'], $skus, "Value {$value} must enable the filter.");
        }

        foreach (['0', 'false'] as $value) {
            $this->getJson("/api/v1/products?in_stock={$value}&limit=50")
                ->assertOk()
                ->assertJsonCount(12, 'data');
        }
    }
}
