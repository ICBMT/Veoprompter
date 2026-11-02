<?php

namespace Tests\Unit;

use Tests\Support\StoryContinuationCases;
use Tests\TestCase;

class StoryContinuationServiceTest extends TestCase
{
    public function test_story_memory_transitions_and_boundaries_preserve_the_source_contract(): void
    {
        StoryContinuationCases::run(fn (bool $condition, string $message) => $this->assertTrue($condition, $message));
    }
}
