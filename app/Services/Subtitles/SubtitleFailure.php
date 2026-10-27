<?php

namespace App\Services\Subtitles;

class SubtitleFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
