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
    public function витрина_отдаёт_остаток_из_проекции(): void
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
    public function keyset_пагинация_обходит_каталог_без_пропусков_и_повторов(): void
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

        $this->assertCount($expected, $seen, 'Обход должен покрыть каталог целиком.');
        $this->assertSame($expected, count(array_unique($seen)), 'Ни один SKU не должен встретиться дважды.');
    }

    #[Test]
    public function фильтр_по_типу_и_наличию_работает(): void
    {
        $this->getJson('/api/v1/products?type=giftcard&limit=50')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $inStock = $this->getJson('/api/v1/products?in_stock=1&limit=50')->assertOk();

        $this->assertSame(['KEY-CS2-PRIME'], array_column($inStock->json('data'), 'sku'));
    }

    #[Test]
    public function выключенный_товар_не_попадает_на_витрину(): void
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
    public function фильтр_наличия_понимает_строковые_значения(): void
    {
        foreach (['1', 'true', 'True'] as $value) {
            $skus = array_column(
                $this->getJson("/api/v1/products?in_stock={$value}&limit=50")->assertOk()->json('data'),
                'sku',
            );

            $this->assertSame(['KEY-CS2-PRIME'], $skus, "Значение {$value} должно включать фильтр.");
        }

        foreach (['0', 'false'] as $value) {
            $this->getJson("/api/v1/products?in_stock={$value}&limit=50")
                ->assertOk()
                ->assertJsonCount(12, 'data');
        }
    }
}
