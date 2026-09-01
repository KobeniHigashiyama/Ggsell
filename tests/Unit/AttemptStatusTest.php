<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Delivery\Enums\AttemptStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AttemptStatusTest extends TestCase
{
    #[Test]
    public function фолбэк_разрешён_только_после_определённого_отказа(): void
    {
        $this->assertTrue(AttemptStatus::Failed->allowsFallback());

        $this->assertFalse(AttemptStatus::Unknown->allowsFallback());
        $this->assertFalse(AttemptStatus::Pending->allowsFallback());
        $this->assertFalse(AttemptStatus::Succeeded->allowsFallback());
    }

    #[Test]
    public function неизвестный_исход_не_считается_выясненным(): void
    {
        $this->assertFalse(AttemptStatus::Unknown->isResolved());
        $this->assertFalse(AttemptStatus::Pending->isResolved());
        $this->assertTrue(AttemptStatus::Succeeded->isResolved());
        $this->assertTrue(AttemptStatus::Failed->isResolved());
    }
}
