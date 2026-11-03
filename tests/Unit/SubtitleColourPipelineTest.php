<?php

namespace Tests\Unit;

use Tests\Support\SubtitleColourCases;
use Tests\TestCase;

class SubtitleColourPipelineTest extends TestCase
{
    public function test_hdr_and_sdr_colour_contracts(): void
    {
        SubtitleColourCases::run(fn (bool $condition, string $label) => $this->assertTrue($condition, $label));
    }
}
