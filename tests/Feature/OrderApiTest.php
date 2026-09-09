<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
    }

    #[Test]
    public function order_is_created_with_price_frozen_at_purchase_time(): void
    {
        $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'])
            ->assertCreated()
            ->assertJsonPath('data.sku', 'KEY-GTA5')
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.amount_minor', 199000);

        $this->assertDatabaseCount('orders', 1);
    }

    #[Test]
    public function code_is_hidden_until_order_is_delivered(): void
    {
        $order = $this->makeOrder('KEY-GTA5', [
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);

        $this->getJson("/api/v1/orders/{$order->public_id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonMissingPath('data.code');
    }

    #[Test]
    public function order_can_hold_several_items_from_different_products(): void
    {
        $this->postJson('/api/v1/orders', ['items' => [
            ['sku' => 'KEY-GTA5'],
            ['sku' => 'KEY-EFT', 'quantity' => 2],
        ]])
            ->assertCreated()
            ->assertJsonPath('data.status', 'created')
            // 1990 + 3490 * 2
            ->assertJsonPath('data.amount_minor', 199000 + 349000 * 2)
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.sku', 'KEY-GTA5')
            ->assertJsonPath('data.items.1.sku', 'KEY-EFT')
            ->assertJsonPath('data.items.2.sku', 'KEY-EFT')
            ->assertJsonPath('data.items.2.position', 3)
            ->assertJsonPath('data.items.0.status', 'pending')
            // Top-level sku is meaningless once an order spans products.
            ->assertJsonMissingPath('data.sku');

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 3);
    }

    #[Test]
    public function quantity_becomes_one_deliverable_item_per_unit(): void
    {
        $response = $this->postJson('/api/v1/orders', ['items' => [
            ['sku' => 'KEY-GTA5', 'quantity' => 3],
        ]])->assertCreated();

        // Each unit needs its own code, so each is its own item and can be
        // delivered or refunded independently.
        $this->assertCount(3, $response->json('data.items'));
        $this->assertSame([1, 2, 3], array_column($response->json('data.items'), 'position'));
        $this->assertSame(199000 * 3, $response->json('data.amount_minor'));
    }

    #[Test]
    public function single_item_order_keeps_stage_one_response_shape(): void
    {
        $this->postJson('/api/v1/orders', ['items' => [['sku' => 'KEY-GTA5']]])
            ->assertCreated()
            ->assertJsonPath('data.sku', 'KEY-GTA5')
            ->assertJsonPath('data.amount_minor', 199000)
            ->assertJsonCount(1, 'data.items');
    }

    #[Test]
    public function mixing_both_request_shapes_is_rejected(): void
    {
        $this->postJson('/api/v1/orders', [
            'sku' => 'KEY-GTA5',
            'items' => [['sku' => 'KEY-EFT']],
        ])->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function unknown_sku_inside_items_is_rejected_by_validation(): void
    {
        $this->postJson('/api/v1/orders', ['items' => [
            ['sku' => 'KEY-GTA5'],
            ['sku' => 'NO-SUCH-SKU'],
        ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.1.sku');

        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function repeated_idempotency_key_does_not_create_second_order(): void
    {
        $headers = ['Idempotency-Key' => 'order-key-1'];
        $payload = ['sku' => 'KEY-GTA5'];

        $first = $this->postJson('/api/v1/orders', $payload, $headers)->assertCreated();

        $second = $this->postJson('/api/v1/orders', $payload, $headers)
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame($first->json('data.order_id'), $second->json('data.order_id'));
        $this->assertDatabaseCount('orders', 1);
    }

    #[Test]
    public function same_idempotency_key_with_different_body_is_rejected(): void
    {
        $headers = ['Idempotency-Key' => 'order-key-2'];

        $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'], $headers)->assertCreated();

        $this->postJson('/api/v1/orders', ['sku' => 'KEY-EFT'], $headers)->assertStatus(409);

        $this->assertDatabaseCount('orders', 1);
    }

    #[Test]
    public function nonexistent_sku_is_rejected_by_validation(): void
    {
        $this->postJson('/api/v1/orders', ['sku' => 'NO-SUCH-SKU'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');
    }

    #[Test]
    public function unknown_order_returns_404(): void
    {
        $this->getJson('/api/v1/orders/ord_missing')->assertNotFound();
    }

    #[Test]
    public function disabled_product_returns_422_instead_of_500(): void
    {
        DB::table('products')
            ->where('sku', 'KEY-GTA5')
            ->update(['is_active' => false]);

        $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');
    }

    #[Test]
    public function malformed_storefront_cursor_returns_400_instead_of_first_page(): void
    {
        $this->getJson('/api/v1/products?cursor=not-a-cursor')->assertStatus(400);
    }
}
