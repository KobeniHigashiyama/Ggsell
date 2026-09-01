<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Delivery\Enums\AttemptStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AttemptStatusTest extends TestCase
{
    #[Test]
    public function fallback_is_allowed_only_after_definitive_rejection(): void
    {
        $this->assertTrue(AttemptStatus::Failed->allowsFallback());

        $this->assertFalse(AttemptStatus::Unknown->allowsFallback());
        $this->assertFalse(AttemptStatus::Pending->allowsFallback());
        $this->assertFalse(AttemptStatus::Succeeded->allowsFallback());
    }

    #[Test]
    public function unknown_outcome_is_not_considered_resolved(): void
    {
        $this->assertFalse(AttemptStatus::Unknown->isResolved());
        $this->assertFalse(AttemptStatus::Pending->isResolved());
        $this->assertTrue(AttemptStatus::Succeeded->isResolved());
        $this->assertTrue(AttemptStatus::Failed->isResolved());
    }
}
