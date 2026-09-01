<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Acceptance criterion 1: fifty concurrent paid webhooks for one order produce
 * exactly one delivery.
 *
 * This separate suite targets the running stack rather than the test database.
 * Row contention requires requests handled by different processes; a single
 * PHPUnit process and connection cannot exercise that race.
 *
 * Run: docker compose exec app php artisan test --testsuite=Integration
 */
class ParallelWebhookRaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->stackIsUp()) {
            $this->markTestSkipped('Stack is not running: docker compose up -d');
        }
    }

    #[Test]
    public function fifty_concurrent_webhooks_produce_exactly_one_delivery(): void
    {
        $this->assertRaceSucceeds('distinct');
    }

    #[Test]
    public function fifty_retries_of_same_event_produce_exactly_one_delivery(): void
    {
        $this->assertRaceSucceeds('same');
    }

    private function assertRaceSucceeds(string $mode): void
    {
        $process = new Process(
            // Leave enough time for a recently restarted worker to begin consuming
            // jobs. This test verifies exactly-once delivery rather than latency,
            // so the margin prevents false failures without weakening the invariant.
            ['php', 'artisan', 'chaos:race', '--n=50', "--mode={$mode}", '--wait=60'],
            base_path(),
            // The command must use the running stack database because requests target
            // its real HTTP endpoint.
            [
                // The subprocess must behave exactly like a normal CLI
                // invocation against the running stack, not like a test.
                // DB_DATABASE, because it drives the real HTTP endpoint and
                // nginx talks to the application database. CACHE_STORE, because
                // PHPUnit forces `array` on this process and the child would
                // inherit it: pinning the supplier stubs would then land in an
                // in-memory cache that PHP-FPM never reads, leaving the stubs
                // in random mode and the check intermittently red for reasons
                // that have nothing to do with concurrency.
                'DB_DATABASE' => 'ggsell',
                'APP_ENV' => 'local',
                'CACHE_STORE' => 'database',
            ],
            null,
            180,
        );

        $process->run();

        $this->assertSame(
            0,
            $process->getExitCode(),
            "Race check failed in {$mode} mode:\n".$process->getOutput().$process->getErrorOutput(),
        );

        $this->assertStringContainsString('exactly one delivery', $process->getOutput());
    }

    private function stackIsUp(): bool
    {
        $handle = curl_init(rtrim((string) config('ggsell.self_url'), '/').'/v1/products?limit=1');
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return $status === 200;
    }
}
