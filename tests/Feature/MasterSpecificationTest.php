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
        $this->assertStringContainsString('SECTION 3 — CHARACTER BIBLE AND CANONICAL CAST', $specification);
        $this->assertStringContainsString('closed, authoritative CANONICAL CHARACTER ROSTER', $specification);
        $this->assertStringContainsString('VISIBLE CHARACTER CAST:', $specification);
        $this->assertStringContainsString('SHOT N CHARACTER IDENTITY LOCK:', $specification);
        $this->assertStringContainsString('Unlisted people, crowds, duplicate bodies, clones, composites, replacements, redesigns, alternate versions, and accidental variants are forbidden.', $specification);
        $this->assertStringContainsString('maximum-quality option', $specification);
        $this->assertStringContainsString('quality-focused option', $specification);
        $this->assertStringContainsString('LOGICAL AND PROCESS-STATE CONTINUITY', $specification);
        $this->assertStringContainsString('workpiece or components moving through intermediate states', $specification);
        $this->assertStringContainsString('Never use Type C to excuse a missing operation', $specification);
        $this->assertStringContainsString('AI-DIRECTED CLIP DURATION', $specification);
        $this->assertStringContainsString('do not default to four or impose an arbitrary maximum', $specification);
        $this->assertStringContainsString('about 2–3 seconds for one simple reveal or movement', $specification);
        $this->assertStringContainsString('Give genuinely complex moments more time than simple beats', $specification);
        $this->assertStringContainsString('distinct voice identity', $specification);
        $this->assertStringContainsString('character-dialogue mode', $specification);
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
