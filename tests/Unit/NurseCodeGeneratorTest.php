<?php

namespace Tests\Unit;

use App\Support\NurseCodeGenerator;
use Tests\TestCase;

class NurseCodeGeneratorTest extends TestCase
{
    /** @test */
    public function it_zero_pads_small_ids_to_three_digits()
    {
        $this->assertSame('NURSE-001', NurseCodeGenerator::forId(1));
        $this->assertSame('NURSE-042', NurseCodeGenerator::forId(42));
        $this->assertSame('NURSE-999', NurseCodeGenerator::forId(999));
    }

    /** @test */
    public function it_does_not_truncate_ids_wider_than_three_digits()
    {
        $this->assertSame('NURSE-1000', NurseCodeGenerator::forId(1000));
        $this->assertSame('NURSE-12345', NurseCodeGenerator::forId(12345));
    }
}
