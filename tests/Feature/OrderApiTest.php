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
        $order = Order::query()->create([
            'public_id' => Order::newPublicId(),
            'sku' => 'KEY-GTA5',
            'quantity' => 1,
            'amount_minor' => 199000,
            'currency' => 'RUB',
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);

        $this->getJson("/api/v1/orders/{$order->public_id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonMissingPath('data.code');
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
