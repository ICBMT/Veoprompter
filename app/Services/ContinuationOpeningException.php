<?php

namespace App\Services;

/** Safe field-level diagnostics; never embeds a prompt, credential or provider body. */
final class ContinuationOpeningException extends PromptGenerationException
{
    public function __construct(public readonly array $issues)
    {
        parent::__construct('The next batch did not open on the saved ending state/presentation. It was not saved; the original story is unchanged.');
    }
}
