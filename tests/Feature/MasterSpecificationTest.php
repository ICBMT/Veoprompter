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
        $this->assertStringContainsString('Check specific process claims against well-established knowledge and for internal consistency', $specification);
        $this->assertStringContainsString('if reliable sources are available and actually consulted, use them to verify details', $specification);
        $this->assertStringContainsString('do not imply source verification', $specification);
        $this->assertStringContainsString('Never use Type C to excuse a missing operation', $specification);
        $this->assertStringContainsString('AI-DIRECTED CLIP DURATION', $specification);
        $this->assertStringContainsString('do not default to four or impose an arbitrary maximum', $specification);
        $this->assertStringContainsString('about 2–3 seconds for one simple reveal or movement', $specification);
        $this->assertStringContainsString('Give genuinely complex moments more time than simple beats', $specification);
        $this->assertStringContainsString('distinct voice identity', $specification);
        $this->assertStringContainsString('character-dialogue mode', $specification);
        $this->assertStringContainsString('TRANSITION CLIP SYSTEM', $specification);
        $this->assertStringContainsString('Do not choose Type A merely because the next clip\'s opening matches the prior ending', $specification);
        $this->assertStringContainsString('If yes, use Type A; open on the exact prior state and continue only that already-visible motion', $specification);
        $this->assertStringContainsString('If yes, use Type B and show the complete trigger-to-result bridge inside that clip', $specification);
        $this->assertStringContainsString('TYPE A — DIRECT CONTINUATION', $specification);
        $this->assertStringContainsString('TYPE B — NATURAL ACTION TRANSITION', $specification);
        $this->assertStringContainsString('TYPE C — NARRATIVE / TEMPORAL TRANSITION', $specification);
        $this->assertStringContainsString('begins with the preceding clip\'s exact physical/process continuity state as its explicit before/opening state', $specification);
        $this->assertStringContainsString('Do not cut directly to the after state.', $specification);
        $this->assertStringContainsString('TRANSITION TYPE: TYPE C — NARRATIVE / TEMPORAL TRANSITION', $specification);
        $this->assertStringContainsString('Speech-to-visual action lock', $specification);
        $this->assertStringContainsString('SPEECH/VISUAL MAP:', $specification);
        $this->assertStringContainsString('CLIP DURATION: N seconds', $specification);
        $this->assertStringContainsString('OPENING CONTINUITY STATE:', $specification);
        $this->assertStringContainsString('VISIBLE CAUSE → ACTION → RESULT:', $specification);
        $this->assertStringContainsString('2–2.5 words/second', $specification);
        $this->assertStringContainsString('do not claim external research, source verification, or citations', $specification);
        $this->assertStringContainsString('No external source retrieval was performed for this plan', $specification);
        $this->assertStringContainsString('label each essential claim as established knowledge, a simplification, or uncertain/disputed', $specification);
        $this->assertStringContainsString('Every clip has one primary idea', $specification);
        $this->assertStringContainsString('never label an unresolved automatic check as passed', $specification);
        $this->assertStringContainsString('The application separately runs deterministic checks', $specification);
        $this->assertStringContainsString('CONTINUITY INPUT NOTE', $specification);
        $this->assertStringContainsString('each previous clip\'s actual rendered final frame as the next clip\'s reference/continuation input', $specification);
        $this->assertStringContainsString('only when the actual previous rendered final frame will be supplied to the target video model as an image input', $specification);
        $this->assertStringContainsString('If no prior image will be supplied—or frame use is unconfirmed—omit this quote from the copyable prompt', $specification);
        $this->assertStringContainsString('in an extension prompt only when its actual reference image is attached', $specification);
        $this->assertStringContainsString('If no image is supplied, use only the written starting-state description and requested action', $specification);
        $this->assertStringContainsString('If neither an image nor a written starting-state description is available, stop and ask the user to provide one before drafting the extension.', $specification);
        $this->assertStringContainsString('Include the exact continuation instruction VERBATIM only when the actual final-frame image is supplied', $specification);
        $this->assertStringContainsString('CONTINUATION SCOPE:', $specification);
        $this->assertStringContainsString('the quote\'s clothing, gesture, step, and light-shift examples are illustrative only', $specification);
        $this->assertStringContainsString('do not add clothing, characters, gestures, steps, props, camera moves, or lighting changes absent from the frame and requested action', $specification);
        $this->assertStringContainsString('cannot guarantee a pixel-matched seam', $specification);
        $this->assertStringContainsString('The destination clip must begin from the exact prior ending in both continuity and presentation', $specification);
        $this->assertStringContainsString('copy the prior `ENDING PRESENTATION STATE:` verbatim into `OPENING PRESENTATION STATE:`', $specification);
        $this->assertStringContainsString('Do not cut directly to the changed destination state or presentation.', $specification);
        $this->assertStringContainsString('For Type A and Type B incoming clips, copy the prior clip\'s `ENDING PRESENTATION STATE:` value exactly into this clip\'s opening', $specification);
        $this->assertStringContainsString('never narrate an off-screen, omitted, earlier, later, or merely implied action', $specification);
        $this->assertStringContainsString('accurate, clearly identified cutaway, diagram, overlay', $specification);
        $this->assertStringContainsString('OUTGOING HANDOFF TYPE: TYPE A — DIRECT CONTINUATION', $specification);
        $this->assertStringContainsString('OUTGOING HANDOFF TYPE: TYPE B — NATURAL ACTION TRANSITION', $specification);
        $this->assertStringContainsString('OUTGOING HANDOFF TYPE: TYPE C — NARRATIVE / TEMPORAL TRANSITION', $specification);
        $this->assertStringContainsString('OUTGOING HANDOFF TYPE: FINAL — NO NEXT HANDOFF', $specification);
        $this->assertStringContainsString("prior clip's `OUTGOING HANDOFF TYPE:` exactly matches the destination clip's `TRANSITION TYPE:`", $specification);
        $this->assertStringContainsString('Do not include it in a Type C narrative/temporal transition clip', $specification);
        $this->assertStringContainsString('MANDATORY CONTINUATION INSTRUCTION', $specification);
        $this->assertStringContainsString('Whatever was already in motion keeps its direction and speed through the cut rather than restarting', $specification);
        $this->assertStringContainsString('Never generate images or videos.', $specification);

        preg_match_all('/^SECTION\s+(\d+)\s+—/mu', $specification, $sections);
        $this->assertSame(range(1, 15), array_map('intval', $sections[1]));
        $this->assertStringContainsString('SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS', $specification);
        $this->assertStringContainsString('COPY CLIP 1 PROMPT', $specification);
        $this->assertStringContainsString('COPY EXTENSION VIDEO PROMPT', $specification);
        $this->assertStringContainsString('SILENT 10-POINT QUALITY GATE', $specification);
    }
}
