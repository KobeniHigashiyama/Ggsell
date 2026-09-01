<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Supplier stub contract.
 *
 * Verifies that the stub returns the same code for the same request_id and that
 * timeout mode represents an issued code with a lost response, not a rejection.
 */
class SupplierStubContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
        $this->seedSupplierKeys('a', 'KEY-CS2-PRIME', 5);
    }

    private function issue(array $payload, array $headers = []): TestResponse
    {
        return $this->postJson('/api/suppliers/a/issue', $payload, $headers);
    }

    #[Test]
    public function repeated_request_id_returns_same_code(): void
    {
        $payload = ['request_id' => 'req_x_a_1', 'sku' => 'KEY-CS2-PRIME', 'order_id' => 'ord_x'];

        $first = $this->issue($payload)->assertOk()->json('code');

        for ($i = 0; $i < 5; $i++) {
            $this->issue($payload)
                ->assertOk()
                ->assertJson(['status' => 'ok', 'code' => $first])
                ->assertHeader('X-Idempotent-Replay', 'true');
        }

        $this->assertSame(1, DB::table('stub.supplier_keys')->where('status', 'issued')->count());
        $this->assertSame(4, DB::table('stub.supplier_keys')->where('status', 'available')->count());
    }

    #[Test]
    public function timeout_mode_consumes_key_before_hanging(): void
    {
        $payload = ['request_id' => 'req_timeout_a_1', 'sku' => 'KEY-CS2-PRIME', 'order_id' => 'ord_t'];

        $this->issue($payload, ['X-Chaos-Mode' => 'timeout'])->assertOk();

        $this->assertSame(1, DB::table('stub.supplier_keys')->where('status', 'issued')->count());

        $issuedCode = DB::table('stub.supplier_requests')->where('request_id', 'req_timeout_a_1')->value('code');
        $this->assertNotNull($issuedCode);

        $this->issue($payload)->assertOk()->assertJson(['code' => $issuedCode]);
        $this->assertSame(1, DB::table('stub.supplier_keys')->where('status', 'issued')->count());
    }

    #[Test]
    public function different_request_ids_receive_different_codes(): void
    {
        $codes = [];

        for ($i = 1; $i <= 5; $i++) {
            $codes[] = $this->issue([
                'request_id' => "req_multi_a_{$i}",
                'sku' => 'KEY-CS2-PRIME',
                'order_id' => "ord_{$i}",
            ])->assertOk()->json('code');
        }

        $this->assertCount(5, array_unique($codes), 'One code cannot be assigned to two orders.');
    }

    #[Test]
    public function empty_pool_returns_explicit_rejection_instead_of_server_error(): void
    {
        DB::table('stub.supplier_keys')->update(['status' => 'issued']);

        $this->issue(['request_id' => 'req_empty_a_1', 'sku' => 'KEY-CS2-PRIME', 'order_id' => 'ord_e'])
            ->assertStatus(409)
            ->assertJson(['status' => 'error', 'reason' => 'out_of_stock']);
    }

    #[Test]
    public function error_mode_does_not_consume_key(): void
    {
        $this->issue(
            ['request_id' => 'req_err_a_1', 'sku' => 'KEY-CS2-PRIME', 'order_id' => 'ord_err'],
            ['X-Chaos-Mode' => 'error'],
        )->assertStatus(503)->assertJson(['status' => 'error']);

        $this->assertSame(5, DB::table('stub.supplier_keys')->where('status', 'available')->count());
    }
}
