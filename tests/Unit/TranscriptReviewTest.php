<?php

namespace Tests\Unit;

use Tests\Support\SubtitleReviewCases;
use Tests\TestCase;

class TranscriptReviewTest extends TestCase
{
    public function test_review_text_timing_hash_and_ass_safety_contracts(): void
    {
        SubtitleReviewCases::run(fn (bool $condition, string $label) => $this->assertTrue($condition, $label));
    }
}
