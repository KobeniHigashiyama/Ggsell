<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The stage-2 scenarios, run against the live stack.
 *
 * Each one is the same command a reviewer would type by hand, so the suite and
 * the README cannot drift apart. They belong here rather than in the feature
 * suite because the properties under test only exist across processes: real
 * queue workers, the scheduler, and two rate limiters that have to agree.
 *
 * Run: docker compose exec app php artisan test --testsuite=Integration
 */
class Stage2ScenariosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->stackIsUp()) {
            $this->markTestSkipped('Stack is not running: docker compose up -d');
        }
    }

    #[Test]
    public function a_partly_undeliverable_order_settles_honestly(): void
    {
        $this->assertScenarioSucceeds(['chaos:partial', '--wait=90']);
    }

    #[Test]
    public function a_duplicating_supplier_harms_nobody(): void
    {
        $this->assertScenarioSucceeds(['chaos:untrusted', 'duplicate', '--orders=3']);
    }

    #[Test]
    public function a_supplier_that_hides_a_code_behind_an_error_is_caught(): void
    {
        $this->assertScenarioSucceeds(['chaos:untrusted', 'error_but_issued', '--orders=2']);
    }

    #[Test]
    public function a_supplier_that_sends_the_wrong_product_is_refused(): void
    {
        $this->assertScenarioSucceeds(['chaos:untrusted', 'foreign_code', '--orders=2']);
    }

    #[Test]
    public function a_spike_is_paced_without_losing_orders(): void
    {
        $this->assertScenarioSucceeds(['chaos:burst', '--n=60', '--wait=150'], timeout: 240);
    }

    /**
     * The combination that matters most: a large batch already in flight when a
     * supplier stops behaving. Each mode fails differently, and each one has to
     * end with every key accounted for and the money adding up.
     */
    #[Test]
    public function a_batch_survives_a_supplier_that_times_out_mid_delivery(): void
    {
        $this->assertScenarioSucceeds(
            ['chaos:storm', '--n=25', '--fail=timeout', '--wait=240', '--converge=300'],
            timeout: 660,
        );
    }

    #[Test]
    public function a_batch_survives_a_supplier_that_hides_codes_behind_errors(): void
    {
        $this->assertScenarioSucceeds(
            ['chaos:storm', '--n=25', '--fail=error_but_issued', '--wait=240', '--converge=300'],
            timeout: 660,
        );
    }

    #[Test]
    public function a_batch_survives_a_supplier_that_duplicates_codes(): void
    {
        $this->assertScenarioSucceeds(
            ['chaos:storm', '--n=25', '--fail=duplicate', '--wait=240', '--converge=300'],
            timeout: 660,
        );
    }

    #[Test]
    public function a_batch_with_no_supply_left_is_refunded_in_full(): void
    {
        $this->assertScenarioSucceeds(
            ['chaos:storm', '--n=20', '--fail=out_of_stock', '--fail-both', '--wait=240', '--converge=300'],
            timeout: 660,
        );
    }

    /** @param  list<string>  $command */
    private function assertScenarioSucceeds(array $command, int $timeout = 180): void
    {
        // Let the workers finish the previous scenario first. Resetting the
        // database out from under a running job leaves the queue recovering into
        // the next scenario, which shows up as an unexplainable failure there.
        $this->runArtisan(['ops:wait-idle', '--timeout=60'], $timeout);

        // Every scenario starts from a known catalog and full supplier pools, so a
        // previous run cannot decide this one's outcome.
        $this->runArtisan(['migrate:fresh', '--seed', '--force'], $timeout);

        $process = $this->runArtisan($command, $timeout);

        $this->assertSame(
            0,
            $process->getExitCode(),
            implode(' ', $command)." failed:\n".$process->getOutput().$process->getErrorOutput(),
        );

        $this->assertStringNotContainsString('FAILED', $process->getOutput());
    }

    /** @param  list<string>  $command */
    private function runArtisan(array $command, int $timeout): Process
    {
        $process = new Process(
            ['php', 'artisan', ...$command],
            base_path(),
            // The subprocess must behave like a normal CLI invocation against the
            // running stack, not like a test: nginx and the workers talk to the
            // application database, and the supplier mode switch lives in the
            // shared cache that PHP-FPM actually reads.
            [
                'DB_DATABASE' => 'ggsell',
                'APP_ENV' => 'local',
                'CACHE_STORE' => 'database',
                'QUEUE_CONNECTION' => 'database',
            ],
            null,
            $timeout,
        );

        $process->run();

        return $process;
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
