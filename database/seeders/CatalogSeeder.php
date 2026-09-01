<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Base catalog with prices converted to minor units. */
class CatalogSeeder extends Seeder
{
    private const PRODUCTS = [
        ['STEAM-TOPUP-500', 'Пополнение Steam 500 ₽', 'topup', 500, 'assets/steam.png'],
        ['STEAM-TOPUP-1000', 'Пополнение Steam 1000 ₽', 'topup', 1000, 'assets/steam.png'],
        ['STEAM-TOPUP-2500', 'Пополнение Steam 2500 ₽', 'topup', 2500, 'assets/steam.png'],
        ['KEY-CS2-PRIME', 'CS2 Prime Status ключ', 'key', 1290, 'assets/cs2.png'],
        ['KEY-GTA5', 'GTA V ключ активации', 'key', 1990, 'assets/gta5.png'],
        ['KEY-EFT', 'Escape from Tarkov ключ', 'key', 3490, 'assets/eft.png'],
        ['SUB-DISCORD-1M', 'Discord Nitro 1 месяц', 'subscription', 399, 'assets/discord.png'],
        ['SUB-YT-3M', 'YouTube Premium 3 месяца', 'subscription', 1490, 'assets/youtube.png'],
        ['SUB-SPOTIFY-1M', 'Spotify Premium 1 месяц', 'subscription', 299, 'assets/spotify.png'],
        ['GIFT-PSN-1000', 'PlayStation Store карта 1000 ₽', 'giftcard', 1000, 'assets/psn.png'],
        ['GIFT-XBOX-1500', 'Xbox Gift Card 1500 ₽', 'giftcard', 1500, 'assets/xbox.png'],
        ['GIFT-ROBLOX-800', 'Roblox 800 Robux', 'giftcard', 890, 'assets/roblox.png'],
    ];

    /**
     * SKUs from the base catalog.
     *
     * Other seeders use this list instead of every table row because catalog:seed-load
     * adds load-test products that do not belong in the base supplier pools.
     *
     * @return list<string>
     */
    public static function skus(): array
    {
        return array_column(self::PRODUCTS, 0);
    }

    public function run(): void
    {
        $now = now();

        foreach (self::PRODUCTS as $rank => [$sku, $name, $type, $price, $image]) {
            DB::table('products')->upsert([[
                'sku' => $sku,
                'name' => $name,
                'type' => $type,
                'price_minor' => $price * 100,
                'currency' => 'RUB',
                'image' => $image,
                'is_active' => true,
                'sort_rank' => $rank,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['sku'], ['name', 'type', 'price_minor', 'currency', 'image', 'is_active', 'sort_rank', 'updated_at']);

            DB::table('product_stock')->upsert([[
                'sku' => $sku,
                'available_count' => 0,
                'issued_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['sku'], ['updated_at']);
        }

        $this->command?->info('Каталог: '.count(self::PRODUCTS).' SKU.');
    }
}
