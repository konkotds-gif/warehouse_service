<?php

namespace Tests\Unit;

use App\Domain\Storage\SetStatus;
use PHPUnit\Framework\TestCase;

class SetStatusTest extends TestCase
{
    public function test_main_lifecycle_is_allowed(): void
    {
        $path = [SetStatus::Accepted, SetStatus::InTransit, SetStatus::Received, SetStatus::Placed,
            SetStatus::Ordered, SetStatus::InTransit, SetStatus::Ready, SetStatus::Issued];
        for ($i = 0; $i < count($path) - 1; $i++) {
            $this->assertTrue($path[$i]->canTransitionTo($path[$i + 1]), $path[$i]->value.' → '.$path[$i + 1]->value);
        }
    }

    public function test_issue_only_from_ready(): void
    {
        foreach (SetStatus::cases() as $status) {
            $this->assertSame($status === SetStatus::Ready, $status->canTransitionTo(SetStatus::Issued), $status->value);
        }
    }

    public function test_issued_is_final(): void
    {
        $this->assertSame([], SetStatus::Issued->allowedNext());
        $this->assertFalse(SetStatus::Issued->isActive());
        $this->assertNotContains('issued', SetStatus::activeValues());
    }
}
