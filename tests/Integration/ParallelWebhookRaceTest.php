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
            $this->markTestSkipped('Стек не поднят: docker compose up -d');
        }
    }

    #[Test]
    public function пятьдесят_параллельных_вебхуков_дают_ровно_одну_выдачу(): void
    {
        $this->assertRaceSucceeds('distinct');
    }

    #[Test]
    public function пятьдесят_повторов_одного_события_дают_ровно_одну_выдачу(): void
    {
        $this->assertRaceSucceeds('same');
    }

    private function assertRaceSucceeds(string $mode): void
    {
        $process = new Process(
            ['php', 'artisan', 'chaos:race', '--n=50', "--mode={$mode}", '--wait=30'],
            base_path(),
            // The command must use the running stack database because requests target
            // its real HTTP endpoint.
            ['DB_DATABASE' => 'ggsell', 'APP_ENV' => 'local'],
            null,
            120,
        );

        $process->run();

        $this->assertSame(
            0,
            $process->getExitCode(),
            "Проверка гонок в режиме {$mode} провалилась:\n".$process->getOutput().$process->getErrorOutput(),
        );

        $this->assertStringContainsString('ровно одна выдача', $process->getOutput());
    }

    private function stackIsUp(): bool
    {
        $handle = curl_init(rtrim((string) config('ggsell.self_url'), '/').'/v1/products?limit=1');
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return $status === 200;
    }
}
