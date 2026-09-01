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
    public function заказ_создаётся_с_ценой_зафиксированной_на_момент_покупки(): void
    {
        $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'])
            ->assertCreated()
            ->assertJsonPath('data.sku', 'KEY-GTA5')
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.amount_minor', 199000);

        $this->assertDatabaseCount('orders', 1);
    }

    #[Test]
    public function код_не_отдаётся_пока_заказ_не_выдан(): void
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
    public function повтор_с_тем_же_ключом_идемпотентности_не_создаёт_второй_заказ(): void
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
    public function тот_же_ключ_с_другим_телом_отвергается(): void
    {
        $headers = ['Idempotency-Key' => 'order-key-2'];

        $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'], $headers)->assertCreated();

        $this->postJson('/api/v1/orders', ['sku' => 'KEY-EFT'], $headers)->assertStatus(409);

        $this->assertDatabaseCount('orders', 1);
    }

    #[Test]
    public function несуществующий_sku_отвергается_валидацией(): void
    {
        $this->postJson('/api/v1/orders', ['sku' => 'NO-SUCH-SKU'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');
    }

    #[Test]
    public function неизвестный_заказ_даёт_404(): void
    {
        $this->getJson('/api/v1/orders/ord_missing')->assertNotFound();
    }

    #[Test]
    public function снятый_с_продажи_товар_даёт_422_а_не_500(): void
    {
        DB::table('products')
            ->where('sku', 'KEY-GTA5')
            ->update(['is_active' => false]);

        $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');
    }

    #[Test]
    public function битый_курсор_витрины_даёт_400_а_не_первую_страницу(): void
    {
        $this->getJson('/api/v1/products?cursor=не-курсор')->assertStatus(400);
    }
}
