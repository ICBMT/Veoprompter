<?php

namespace Tests\Support;

use App\Services\PromptGenerationException;
use App\Services\PromptOutputAuditor;
use App\Services\StoryContinuationService;

/** Same executable story-contract suite for native PHPUnit and the standalone runner. */
final class StoryContinuationCases
{
    public static function footer(string $status = 'open', string $summary = 'The wheel is turning; its purpose is still being investigated.'): string
    {
        return "\nSECTION 11 — STORY MEMORY\nSTORY STATUS: ".strtoupper($status)."\nSTORY HANDOFF TYPE: TYPE A — DIRECT CONTINUATION\nSTORY MEMORY: ".json_encode([
            'summary' => $summary, 'open_threads' => $status === 'open' ? ['Discover what drives the wheel.'] : [],
            'resolved_threads' => ['The wheel has started turning.'], 'important_facts' => ['OBJ-01 has a silver rim.'],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    public static function run(callable $verify): void
    {
        $service = new StoryContinuationService(new PromptOutputAuditor);
        $brief = [...PromptQaCases::input(), 'content_form' => 'long'];
        $package = PromptQaCases::package();
        $root = $package.self::footer();
        $context = $service->initial($root, $brief);
        $verify($context['memory']['open_threads'] !== [], 'A long-form opening retains an explicit open thread.');
        $verify($context['anchors']['OBJ-01'] === 'One cobalt wheel with a silver rim.', 'Canonical identity is captured literally.');
        $reject = function (callable $call, string $message) use ($verify): void {
            try {
                $call();
                $rejected = false;
            } catch (PromptGenerationException) {
                $rejected = true;
            }
            $verify($rejected, $message);
        };
        $reject(fn () => $service->initial($package, $brief), 'Long-form needs readable memory/status.');
        $reject(fn () => $service->initial('No clip blocks', $brief), 'An unparsed long-form output is not continuation-ready.');
        $verify($service->initial('No clip blocks', [...$brief, 'content_form' => 'short']) === [], 'Unparsed short-form still follows its old review behavior.');
        $verify(StoryContinuationService::instructions([...$brief, 'content_form' => 'short']) === '', 'Ordinary short-form has no new generation instructions.');
        $verify(StoryContinuationService::instructions([...$brief, 'mode' => 'extend']) === '', 'Original image-extension instructions remain untouched.');
        $input = $service->prepare($root, $brief, $context, 'open', 'continue', 'Follow the same wheel without replaying dialogue.', 'set');
        $verify($input['clip_count'] === 'quality', 'Set generation keeps the saved count preference.');
        $verify($input['_story_context']['last_clip'] !== '', 'The actual last clip is included.');
        $verify(count($input['_story_context']['recent_batch']) === 2, 'Recent beats are included, not just the last frame.');
        $verify($input['_story_context']['recent_batch'][0]['spoken_rows'][0]['line'] === '“It keeps turning.”', 'Recent exact spoken words survive for no-replay context.');
        $verify(str_contains(StoryContinuationService::instructions($input), 'Follow the same wheel'), 'The caller gets the explicit next-action direction.');
        $accepted = $service->accept($root, $input);
        $verify($accepted['anchors'] === $context['anchors'], 'No identity redesign across a valid batch.');
        $single = $service->prepare($root, $brief, $context, 'open', 'continue', '', 'single');
        $verify($single['clip_count'] === 1 && $single['_story_context']['recipe_clip_count'] === 'quality', 'One-clip generation does not lose the original set recipe.');
        $setAgain = $service->prepare($root, [...$brief, 'clip_count' => 1], $accepted, 'open', 'continue', '', 'set');
        $verify($setAgain['clip_count'] === 'quality', 'A set after a single clip restores the recipe.');
        $finish = $service->prepare($root, $brief, $context, 'open', 'finish', 'Explain the drive and resolve the mystery.', 'set');
        $verify($finish['_story_context']['target_status'] === 'ended', 'Finish explicitly requests a resolved ending.');
        $closed = $package.self::footer('ended');
        $closedContext = $service->accept($closed, $finish);
        $verify($closedContext['memory']['open_threads'] === [], 'The final batch has no declared unresolved threads.');
        $sequel = $service->prepare($closed, $brief, $closedContext, 'ended', 'sequel', 'Start another investigation.', 'set');
        $verify($sequel['_story_context']['target_status'] === 'open', 'A sequel opens a new part with room to continue.');
        $reject(fn () => $service->prepare($root, $brief, $context, 'open', 'sequel', '', 'set'), 'Open parts cannot skip ending via sequel.');
        $reject(fn () => $service->prepare($closed, $brief, $closedContext, 'ended', 'continue', '', 'set'), 'Completed parts require the next-part intent.');
        $reject(fn () => $service->accept($root, $finish), 'An OPEN response cannot masquerade as an ending.');
        $reject(fn () => $service->accept($package, $input), 'A new batch without memory is rejected.');
        $singleBody = str_replace('OUTGOING HANDOFF TYPE: TYPE A — DIRECT CONTINUATION', 'OUTGOING HANDOFF TYPE: FINAL — NO NEXT HANDOFF', PromptQaCases::clip(1));
        $singlePrefix = "CANONICAL CHARACTER ROSTER: None\nCANONICAL IDENTITY OBJ-01: One cobalt wheel with a silver rim.\n";
        $bridge = str_replace(['ENDING CONTINUITY STATE: '.PromptQaCases::state(), 'VISIBLE CAUSE → ACTION → RESULT: The same wheel turns under a controlled drive.'],
            ['ENDING CONTINUITY STATE: '.str_replace('location=workshop', 'location=rack', PromptQaCases::state()), 'VISIBLE CAUSE → ACTION → RESULT: The same wheel rolls from the workshop to the rack under a visible push.'], $singleBody);
        $bridge = $singlePrefix.$bridge.str_replace('TYPE A — DIRECT CONTINUATION', 'TYPE B — NATURAL ACTION TRANSITION', self::footer());
        $verify($service->accept($bridge, $input)['memory']['open_threads'] !== [], 'A matched opening with a visible Type B bridge is accepted.');
        $temporal = str_replace(['ENDING CONTINUITY STATE: '.PromptQaCases::state(), 'VISIBLE CAUSE → ACTION → RESULT: The same wheel turns under a controlled drive.'],
            ['ENDING CONTINUITY STATE: cast=None; object=wheel OBJ-01; stage=complete; location=workshop; motion=stationary', 'VISIBLE CAUSE → ACTION → RESULT: Before state: the wheel is turning in the workshop. A time-lapse shows it cooling and slowing. After state: the completed wheel is stationary in the workshop.'], $singleBody);
        $temporal = $singlePrefix.$temporal.str_replace('TYPE A — DIRECT CONTINUATION', 'TYPE C — NARRATIVE / TEMPORAL TRANSITION', self::footer('open', 'The wheel has stopped and cooled; the investigation continues.'));
        $verify($service->accept($temporal, $input)['memory']['open_threads'] !== [], 'An explicit non-seamless Type C before/transition/after progression is accepted.');
        $hero = PromptQaCases::characters($package).self::footer();
        $heroContext = $service->initial($hero, $brief);
        $heroInput = $service->prepare($hero, $brief, $heroContext, 'open', 'continue', '', 'set');
        $verify($heroContext['approved_cast']['CHAR-01'] === 1, 'The established individual count is carried separately from the historical bible.');
        $cloned = str_replace(['CANONICAL CHARACTER ROSTER: CHAR-01 ×1', 'VISIBLE CHARACTER CAST: Mira (CHAR-01) ×1'], ['CANONICAL CHARACTER ROSTER: CHAR-01 ×2', 'VISIBLE CHARACTER CAST: Mira (CHAR-01) ×2'], $hero);
        $reject(fn () => $service->accept($cloned, $heroInput), 'A consistent-but-newly-cloned roster cannot change the previous individual count.');
        $reject(fn () => $service->accept(str_replace('STORY HANDOFF TYPE: TYPE A — DIRECT CONTINUATION', 'STORY HANDOFF TYPE: TYPE C — NARRATIVE / TEMPORAL TRANSITION', $root), $input), 'A Type C external junction needs explicit visible before/transition/after evidence.');
        $reject(fn () => $service->accept(str_replace('STORY HANDOFF TYPE: TYPE A — DIRECT CONTINUATION', '', $root), $input), 'External handoff classification is mandatory and independent of local INITIAL.');
        $badState = PromptQaCases::edit($root, 1, 'OPENING CONTINUITY STATE: '.PromptQaCases::state(), 'OPENING CONTINUITY STATE: cast=None; object=another wheel');
        $reject(fn () => $service->accept($badState, $input), 'A cross-batch teleport is rejected.');
        $badCamera = PromptQaCases::edit($root, 1, 'OPENING PRESENTATION STATE: camera=static', 'OPENING PRESENTATION STATE: camera=aerial');
        $reject(fn () => $service->accept($badCamera, $input), 'A cross-batch camera jump is rejected.');
        $reject(fn () => $service->accept(str_replace('CANONICAL IDENTITY OBJ-01: One cobalt wheel', 'CANONICAL IDENTITY OBJ-01: One orange wheel', $root), $input), 'Canonical redesign is rejected.');
        $extra = str_replace('SECTION 3 — CHARACTER BIBLE', "SECTION 3 — CHARACTER BIBLE\nCANONICAL IDENTITY OBJ-02: A new copper gear.", $root);
        $reject(fn () => $service->accept($extra, $input), 'A new recurring subject needs explicit permission.');
        $permitted = $service->prepare($root, $brief, $context, 'open', 'continue', 'Introduce a new copper gear.', 'set', true);
        $newContext = $service->accept($extra, $permitted);
        $verify(isset($newContext['anchors']['OBJ-02']) && str_contains($newContext['canon'], 'A new copper gear.'), 'Approved new anchors persist in the growing ledger.');
        $reject(fn () => $service->prepare($root, $brief, [...$context, 'canon' => str_repeat('x', StoryContinuationService::MAX_CANON_BYTES + 1)], 'open', 'continue', '', 'set'), 'Oversized canon fails safely instead of being truncated.');
        $ambiguous = $root."STORY STATUS: OPEN\n";
        $reject(fn () => $service->accept($ambiguous, $input), 'Duplicate status is not first-match authoritative.');
        $reject(fn () => $service->accept($root."STORY MEMORY: invalid JSON\n", $input), 'Duplicate/malformed memory is rejected.');
        $missing = PromptQaCases::edit($root, 2, 'ENDING CONTINUITY STATE: '.PromptQaCases::state(), '');
        $reject(fn () => $service->prepare($missing, $brief, $context, 'open', 'continue', '', 'set'), 'Missing source ending blocks unsafe continuation.');
        $unreadable = str_replace('0.2–2.2s | VISIBLE ACTION:', 'untimed | VISIBLE ACTION:', $root);
        $reject(fn () => $service->prepare($unreadable, $brief, $context, 'open', 'continue', '', 'set'), 'Unreadable source dialogue is not silently omitted from story context.');
        $repair = PromptQaCases::edit($root, 2, 'ENDING CONTINUITY STATE: '.PromptQaCases::state(), 'ENDING CONTINUITY STATE: cast=None; object=wheel OBJ-01; stage=repaired');
        $current = $service->prepare($repair, $brief, $context, 'open', 'continue', '', 'set');
        $verify(str_contains($current['_story_context']['ending_state'], 'repaired'), 'Current repaired ending takes precedence over saved memory.');
        $verify(! isset($accepted['last_clip'], $accepted['recent_batch'], $accepted['source_memory']), 'Saved memory does not recursively embed the full prior request.');
        $loop = $context;
        for ($i = 0; $i < 25; $i++) {
            $next = $service->prepare($root, $brief, $loop, 'open', 'continue', 'Advance the investigation.', 'set');
            $loop = $service->accept($root, $next);
        }
        $verify(strlen(json_encode($loop)) === strlen(json_encode($context)), 'Repeated batches do not recursively grow the prompt context.');
    }
}
