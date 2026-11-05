<?php

namespace Tests\Unit;

use Tests\Support\SubtitlePaginationCases;
use Tests\TestCase;

class SubtitlePaginationTest extends TestCase
{
    public function test_long_caption_pages_preserve_words_timing_and_readable_layout(): void
    {
        SubtitlePaginationCases::run(fn (bool $condition, string $label) => $this->assertTrue($condition, $label));
    }
}
