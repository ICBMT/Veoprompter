<?php

namespace Tests\Feature;

use Tests\TestCase;

class MasterSpecificationTest extends TestCase
{
    public function test_master_spec_preserves_the_extension_contract_and_continuation_instruction(): void
    {
        $specification = file_get_contents(resource_path('prompts/master-spec.txt'));

        $this->assertIsString($specification);

        $source = file_get_contents(base_path('prompt.txt'));
        $opening = 'content = r"""';
        $contentStart = is_string($source) ? strpos($source, $opening) : false;
        $contentEnd = is_string($source) ? strrpos($source, '"""') : false;
        $this->assertNotFalse($contentStart, 'The root prompt.txt master specification must be present.');
        $this->assertNotFalse($contentEnd, 'The root prompt.txt master specification must be complete.');
        $rootSpecification = substr($source, $contentStart + strlen($opening), $contentEnd - ($contentStart + strlen($opening)));
        $this->assertSame($rootSpecification, $specification, 'The AI prompt used at runtime must remain an exact copy of root prompt.txt.');

        $this->assertStringContainsString('PLAN BEFORE WRITING', $specification);
        $this->assertStringContainsString('AI-DIRECTED CLIP ARCHITECTURE', $specification);
        $this->assertStringContainsString('AI-DIRECTED CLIP DURATION', $specification);
        $this->assertStringContainsString('do not default to four or impose an arbitrary maximum', $specification);
        $this->assertStringContainsString('a simple reveal may need only a few seconds', $specification);
        $this->assertStringContainsString('TRANSITION CLIP SYSTEM', $specification);
        $this->assertStringContainsString('TYPE A — DIRECT CONTINUATION', $specification);
        $this->assertStringContainsString('TYPE B — NATURAL ACTION TRANSITION', $specification);
        $this->assertStringContainsString('TYPE C — NARRATIVE / TEMPORAL TRANSITION', $specification);
        $this->assertStringContainsString('TRANSITION TYPE: TYPE C — NARRATIVE / TEMPORAL TRANSITION', $specification);
        $this->assertStringContainsString('Do not include it in a Type C narrative/temporal transition clip', $specification);
        $this->assertStringContainsString('MANDATORY CONTINUATION INSTRUCTION', $specification);
        $this->assertStringContainsString('Whatever was already in motion keeps its direction and speed through the cut rather than restarting', $specification);
        $this->assertStringContainsString('Never generate images or videos.', $specification);

        preg_match_all('/^SECTION\s+(\d+)\s+—/mu', $specification, $sections);
        $this->assertSame(range(1, 14), array_map('intval', $sections[1]));
        $this->assertStringContainsString('SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS', $specification);
        $this->assertStringContainsString('COPY CLIP 1 PROMPT', $specification);
        $this->assertStringContainsString('COPY EXTENSION VIDEO PROMPT', $specification);
        $this->assertStringContainsString('SILENT 10-POINT QUALITY GATE', $specification);
    }
}
