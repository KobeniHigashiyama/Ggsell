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

    /**
     * The response names the product the code belongs to, which is what lets the
     * core check a supplier instead of believing it.
     */
    #[Test]
    public function successful_response_names_the_product(): void
    {
        $this->issue(['request_id' => 'req_sku_a_1', 'sku' => 'KEY-CS2-PRIME', 'order_id' => 'ord_s'])
            ->assertOk()
            ->assertJsonPath('sku', 'KEY-CS2-PRIME');
    }

    #[Test]
    public function duplicate_mode_hands_over_a_code_it_already_issued(): void
    {
        $first = $this->issue(['request_id' => 'req_dup_a_1', 'sku' => 'KEY-CS2-PRIME', 'order_id' => 'ord_1'])
            ->assertOk()->json('code');

        $second = $this->issue(
            ['request_id' => 'req_dup_a_2', 'sku' => 'KEY-CS2-PRIME', 'order_id' => 'ord_2'],
            ['X-Chaos-Mode' => 'duplicate'],
        )->assertOk()->json('code');

        $this->assertSame($first, $second, 'Duplicate mode must hand over the same code twice.');

        // Only one key was ever consumed, which is exactly why this is dishonest:
        // the supplier sold one thing to two customers.
        $this->assertSame(1, DB::table('stub.supplier_keys')->where('status', 'issued')->count());
        $this->assertSame(2, DB::table('stub.supplier_requests')->where('code', $first)->count());
    }

    #[Test]
    public function foreign_code_mode_returns_a_code_from_another_pool(): void
    {
        $this->seedSupplierKeys('a', 'KEY-GTA5', 3, 'GTA');

        $response = $this->issue(
            ['request_id' => 'req_foreign_a_1', 'sku' => 'KEY-CS2-PRIME', 'order_id' => 'ord_f'],
            ['X-Chaos-Mode' => 'foreign_code'],
        )->assertOk();

        // The supplier grabbed the wrong box and says so: the mismatch between the
        // requested SKU and the reported one is what the core catches.
        $this->assertSame('KEY-GTA5', $response->json('sku'));
        $this->assertStringStartsWith('GTA-', (string) $response->json('code'));
    }

    /**
     * Task 2, point 3: the supplier issues the code and then reports failure.
     * Asking again under the same request id must reveal the code, not issue a
     * second one.
     */
    #[Test]
    public function error_but_issued_mode_hides_a_real_code_behind_a_failure(): void
    {
        $payload = ['request_id' => 'req_hidden_a_1', 'sku' => 'KEY-CS2-PRIME', 'order_id' => 'ord_h'];

        $this->issue($payload, ['X-Chaos-Mode' => 'error_but_issued'])
            ->assertStatus(503)
            ->assertJson(['status' => 'error', 'reason' => 'supplier_error']);

        // A key really was consumed despite the failure.
        $this->assertSame(1, DB::table('stub.supplier_keys')->where('status', 'issued')->count());
        $hidden = DB::table('stub.supplier_requests')->where('request_id', $payload['request_id'])->value('code');
        $this->assertNotNull($hidden);

        // The retry returns that code instead of consuming a second key.
        $this->issue($payload)->assertOk()->assertJson(['code' => $hidden]);
        $this->assertSame(1, DB::table('stub.supplier_keys')->where('status', 'issued')->count());

        // And the audit endpoint reveals it without issuing anything at all.
        $this->getJson('/api/suppliers/a/requests/'.$payload['request_id'])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'code' => $hidden, 'sku' => 'KEY-CS2-PRIME']);
    }

    #[Test]
    public function verifying_an_unknown_request_proves_nothing_was_issued(): void
    {
        $this->getJson('/api/suppliers/a/requests/req_never_sent')
            ->assertStatus(404)
            ->assertJson(['status' => 'error', 'reason' => 'unknown_request']);
    }

    #[Test]
    public function a_returned_code_is_revoked_rather_than_resold(): void
    {
        $code = $this->issue(['request_id' => 'req_ret_a_1', 'sku' => 'KEY-CS2-PRIME', 'order_id' => 'ord_r'])
            ->assertOk()->json('code');

        $this->postJson('/api/suppliers/a/return', ['code' => $code, 'reason' => 'duplicate_code'])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'revoked' => true]);

        $this->assertSame('revoked', DB::table('stub.supplier_keys')->where('code', $code)->value('status'));

        // Returning twice is safe; the second call simply finds nothing to revoke.
        $this->postJson('/api/suppliers/a/return', ['code' => $code])
            ->assertOk()
            ->assertJson(['revoked' => false]);

        // A revoked key never comes back into circulation.
        $reissued = $this->issue(['request_id' => 'req_ret_a_2', 'sku' => 'KEY-CS2-PRIME', 'order_id' => 'ord_r2'])
            ->assertOk()->json('code');
        $this->assertNotSame($code, $reissued);
    }
}
