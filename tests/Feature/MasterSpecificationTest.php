<?php

namespace Tests\Feature;

use Tests\TestCase;

class MasterSpecificationTest extends TestCase
{
    public function test_extracted_master_spec_keeps_the_mandatory_continuation_instruction(): void
    {
        $specification = file_get_contents(resource_path('prompts/master-spec.txt'));

        $this->assertIsString($specification);
        $this->assertStringContainsString('MANDATORY CONTINUATION INSTRUCTION', $specification);
        $this->assertStringContainsString('Whatever was already in motion keeps its direction and speed through the cut rather than restarting', $specification);
        $this->assertStringContainsString('Never generate images or videos.', $specification);
    }
}
