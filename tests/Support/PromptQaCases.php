<?php

namespace Tests\Support;

/** Topic-independent valid/malformed packages; no provider calls or topic-specific repair exceptions. */
final class PromptQaCases
{
    public static function input(): array { return ['mode' => 'story', 'clip_count' => 'quality', 'clip_duration' => 'auto', 'narration' => '1', 'video_type' => 'custom']; }
    public static function state(string $topic = 'wheel'): string { return 'cast=None; object='.$topic.' OBJ-01; stage=in progress; location=workshop; motion=turning'; }
    public static function clip(int $number, string $topic = 'wheel'): string
    {
        $s = self::state($topic);
        $in = $number === 1 ? 'INITIAL CLIP — NO PREVIOUS HANDOFF' : 'TYPE A — DIRECT CONTINUATION';
        $out = $number === 1 ? 'TYPE A — DIRECT CONTINUATION' : 'FINAL — NO NEXT HANDOFF';
        return "COPY CLIP {$number} PROMPT\n```text\nTRANSITION TYPE: {$in}\nVISIBLE CHARACTER CAST: None\nCHARACTER IDENTITY LOCK: None\nVISIBLE SUBJECT IDS: OBJ-01\nSUBJECT IDENTITY LOCK: One cobalt {$topic} with a silver rim.\nCLIP DURATION: 6 seconds\nPRIMARY IDEA: Controlled motion.\nVISUAL REPRESENTATION: LITERAL SCENE\nVISIBLE CAUSE → ACTION → RESULT: The same {$topic} turns under a controlled drive.\nOPENING CONTINUITY STATE: {$s}\nENDING CONTINUITY STATE: {$s}\nOPENING PRESENTATION STATE: camera=static; lighting=daylight; audio=quiet\nENDING PRESENTATION STATE: camera=static; lighting=daylight; audio=quiet\nSPEECH/VISUAL MAP:\n- 0.2–2.2s | VISIBLE ACTION: the {$topic} turns | SPEAKER: Narrator | LINE: “It keeps turning.”\nOUTGOING HANDOFF TYPE: {$out}\n```\n";
    }
    public static function package(string $topic = 'wheel'): string
    {
        return "SECTION 1 — PROJECT OVERVIEW\nClip 1: 6 seconds\nClip 2: 6 seconds\nApproximate total runtime: 12 seconds\nSECTION 3 — CHARACTER BIBLE\nCANONICAL CHARACTER ROSTER: None\nCANONICAL IDENTITY OBJ-01: One cobalt {$topic} with a silver rim.\nSECTION 7 — CLIP PLAN AND CONTINUITY MAP\nClip 1 — 6 seconds\nClip 2 — 6 seconds\nSECTION 8 — COPY-AND-PASTE VIDEO PROMPTS\n".self::clip(1, $topic).self::clip(2, $topic)."SECTION 9 — EXACT HANDOFF SPECIFICATION\nHANDOFF 1 -> 2: TYPE A — DIRECT CONTINUATION\nSECTION 10 — FINAL CONTINUITY AUDIT\nHuman review is needed for actual rendering.\n";
    }
    public static function edit(string $content, int $clip, string $old, string $new): string
    {
        $start = strpos($content, 'COPY CLIP '.$clip.' PROMPT');
        $end = strpos($content, "\n```", strpos($content, "\n```text", $start) + 4);
        $length = $end === false ? strlen($content) - $start : $end - $start;
        return substr_replace($content, str_replace($old, $new, substr($content, $start, $length)), $start, $length);
    }
    public static function characters(string $content): string
    {
        return str_replace(['CANONICAL CHARACTER ROSTER: None', 'VISIBLE CHARACTER CAST: None', 'CHARACTER IDENTITY LOCK: None', 'cast=None'],
            ["CANONICAL CHARACTER ROSTER: CHAR-01 ×1\nCANONICAL IDENTITY CHAR-01: Mira has an oval face, amber eyes and a green coat.", 'VISIBLE CHARACTER CAST: Mira (CHAR-01) ×1', 'CHARACTER IDENTITY LOCK: Mira has an oval face, amber eyes and a green coat.', 'cast=Mira CHAR-01 x1'], $content);
    }
    public static function template(): string
    {
        $text = PromptQaCases::characters(PromptQaCases::package());
        $text = str_replace(['Clip 1: 6 seconds', 'Clip 2: 6 seconds', 'Clip 1 — 6 seconds', 'Clip 2 — 6 seconds', 'Approximate total runtime: 12 seconds', 'HANDOFF 1 -> 2: TYPE A — DIRECT CONTINUATION',
            'CHARACTER IDENTITY LOCK: Mira has an oval face, amber eyes and a green coat.', 'SUBJECT IDENTITY LOCK: One cobalt wheel with a silver rim.'],
            ['Clip 1: {{VEO_CLIP_SECONDS:1}} seconds', 'Clip 2: {{VEO_CLIP_SECONDS:2}} seconds', 'Clip 1 — {{VEO_CLIP_SECONDS:1}} seconds', 'Clip 2 — {{VEO_CLIP_SECONDS:2}} seconds', 'Approximate total runtime: {{VEO_TOTAL_SECONDS}} seconds', 'HANDOFF 1 -> 2: {{VEO_HANDOFF:1}}',
            'CHARACTER IDENTITY LOCK: {{VEO_IDENTITY:CHAR-01}}', 'SUBJECT IDENTITY LOCK: {{VEO_IDENTITY:OBJ-01}}'], $text);
        $text = PromptQaCases::edit($text, 2, 'OPENING CONTINUITY STATE: '.str_replace('cast=None', 'cast=Mira CHAR-01 x1', PromptQaCases::state()), 'OPENING CONTINUITY STATE: {{VEO_PREVIOUS_END_STATE}}');
        $text = PromptQaCases::edit($text, 2, 'OPENING PRESENTATION STATE: camera=static; lighting=daylight; audio=quiet', 'OPENING PRESENTATION STATE: {{VEO_PREVIOUS_END_PRESENTATION}}');
        return PromptQaCases::edit($text, 2, 'TRANSITION TYPE: TYPE A — DIRECT CONTINUATION', 'TRANSITION TYPE: {{VEO_INCOMING_HANDOFF}}');
    }

    /** Each entry has a valid and a deliberately malformed final serialized package. */
    public static function all(string $topic = 'wheel'): array
    {
        $base = self::package($topic); $input = self::input(); $cases = [];
        $add = function (string $code, string $bad, array $badInput = [], ?string $valid = null, array $validInput = []) use (&$cases, $base, $input): void {
            $cases[$code] = ['valid' => $valid ?? $base, 'invalid' => $bad, 'valid_input' => [...$input, ...$validInput], 'invalid_input' => [...$input, ...$badInput]];
        };
        $edit = fn (string $old, string $new, int $n = 1): string => self::edit($base, $n, $old, $new);
        $row = "- 0.2–2.2s | VISIBLE ACTION: the {$topic} turns | SPEAKER: Narrator | LINE: “It keeps turning.”";
        $add('prompt_structure_missing', 'A concept with no copy-ready blocks.');
        $add('clip_count_mismatch', $base, ['clip_count' => 3], validInput: ['clip_count' => 2]);
        $add('clip_numbering', str_replace('COPY CLIP 2 PROMPT', 'COPY CLIP 4 PROMPT', $base));
        foreach (['clip_duration_missing' => '', 'clip_duration_invalid' => 'CLIP DURATION: 0 seconds', 'ai_duration_over_limit' => 'CLIP DURATION: 12 seconds'] as $code => $value) $add($code, $edit('CLIP DURATION: 6 seconds', $value));
        $add('manual_duration_mismatch', $base, ['clip_duration' => 8], validInput: ['clip_duration' => 6]);
        $add('declared_duration_constraint_violation', $base, ['creative_direction' => 'All clips must stay between 7 and 9 seconds.']);
        $add('duration_constraint_conflict', $base, ['clip_duration' => 8, 'creative_direction' => 'All clips must stay between 3 and 7 seconds.']);
        $add('project_runtime_constraint_conflict', $base, ['project_runtime_min_seconds' => 20, 'project_runtime_max_seconds' => 10]);
        $add('project_runtime_constraint_violation', $base, ['project_runtime_max_seconds' => 10]);
        $add('required_field_missing', $edit('PRIMARY IDEA: Controlled motion.', ''));
        $add('metadata_field_ambiguous', $edit('CLIP DURATION: 6 seconds', "CLIP DURATION: 6 seconds\nCLIP DURATION: 9 seconds"));
        $add('visual_representation_unclear', $edit('VISUAL REPRESENTATION: LITERAL SCENE', 'VISUAL REPRESENTATION: Unspecified'));
        $add('first_clip_transition', $edit('INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE A — DIRECT CONTINUATION'));
        $add('incoming_handoff_missing', $edit('TRANSITION TYPE: TYPE A — DIRECT CONTINUATION', 'TRANSITION TYPE: Unknown', 2));
        $add('outgoing_handoff_missing', $edit('OUTGOING HANDOFF TYPE: TYPE A — DIRECT CONTINUATION', ''));
        $add('final_handoff_marker_missing', $edit('OUTGOING HANDOFF TYPE: FINAL — NO NEXT HANDOFF', '', 2));
        $add('handoff_type_mismatch', $edit('OUTGOING HANDOFF TYPE: TYPE A — DIRECT CONTINUATION', 'OUTGOING HANDOFF TYPE: TYPE B — NATURAL ACTION TRANSITION'));
        $add('handoff_label_description_mismatch', $edit('OUTGOING HANDOFF TYPE: TYPE A — DIRECT CONTINUATION', 'OUTGOING HANDOFF TYPE: TYPE A — NATURAL ACTION TRANSITION'));
        $add('handoff_summary_mismatch', str_replace('HANDOFF 1 -> 2: TYPE A', 'HANDOFF 1 -> 2: TYPE C', $base));
        $add('continuity_state_mismatch', $edit('OPENING CONTINUITY STATE: '.self::state($topic), 'OPENING CONTINUITY STATE: '.str_replace('OBJ-01', 'OBJ-02', self::state($topic)), 2));
        $add('presentation_state_mismatch', $edit('OPENING PRESENTATION STATE: camera=static', 'OPENING PRESENTATION STATE: camera=tracking', 2));
        $s = self::state($topic);
        foreach (['continuity_prose_state_mismatch' => 'object=red door; state=closed', 'continuity_prose_state_unverified' => 'A distant unfamiliar landscape.'] as $code => $prose) {
            $v = self::edit($base, 1, 'PRIMARY IDEA:', 'EXACT OPENING FRAME: '.$s."\nPRIMARY IDEA:");
            if (str_ends_with($code, 'mismatch')) {
                $v = str_replace($s, 'cast=None; object=red door; state=open', $v);
            }
            $add($code, self::edit($v, 1, 'EXACT OPENING FRAME: '.(str_ends_with($code, 'mismatch') ? 'cast=None; object=red door; state=open' : $s), 'EXACT OPENING FRAME: '.$prose), valid: $v);
        }
        $v = self::edit($base, 2, 'PRIMARY IDEA:', 'EXACT OPENING FRAME: '.$s."\nPRIMARY IDEA:");
        $add('handoff_opening_prose_unverified', self::edit($v, 2, 'EXACT OPENING FRAME: '.$s, 'EXACT OPENING FRAME: An unrelated panorama.'), valid: $v);
        $door = str_replace($s, 'cast=None; object=red door; state=open', $base);
        $v = self::edit($door, 2, 'PRIMARY IDEA:', "EXACT OPENING FRAME: cast=None; object=red door; state=open\nPRIMARY IDEA:");
        $add('handoff_opening_prose_mismatch', self::edit($v, 2, 'EXACT OPENING FRAME: cast=None; object=red door; state=open', 'EXACT OPENING FRAME: cast=None; object=red door; state=closed'), valid: $v);
        $v = self::edit(self::edit($door, 1, 'PRIMARY IDEA:', "EXACT ENDING STATE: cast=None; object=red door; state=open\nPRIMARY IDEA:"), 2, 'PRIMARY IDEA:', "EXACT OPENING FRAME: cast=None; object=red door; state=open\nPRIMARY IDEA:");
        $add('handoff_prose_state_mismatch', self::edit($v, 2, 'EXACT OPENING FRAME: cast=None; object=red door; state=open', 'EXACT OPENING FRAME: cast=None; object=red door; state=closed'), valid: $v);
        $add('handoff_prose_state_unverified', self::edit($v, 2, 'EXACT OPENING FRAME: cast=None; object=red door; state=open', 'EXACT OPENING FRAME: An unfamiliar panorama.'), valid: $v);
        $add('overview_clip_durations_unverifiable', str_replace(["Clip 1: 6 seconds\n", "Clip 2: 6 seconds\n"], '', $base));
        $add('overview_total_runtime_missing', str_replace('Approximate total runtime: 12 seconds', 'Total: unknown', $base));
        $add('overview_clip_duration_missing', str_replace("Clip 2: 6 seconds\n", '', $base));
        $add('overview_clip_duration_unknown', str_replace('Clip 2: 6 seconds', 'Clip 3: 6 seconds', $base));
        $add('overview_clip_duration_mismatch', str_replace('Clip 1: 6 seconds', 'Clip 1: 7 seconds', $base));
        $add('overview_total_runtime_mismatch', str_replace('Approximate total runtime: 12 seconds', 'Approximate total runtime: 13 seconds', $base));
        $add('clip_plan_duration_missing', str_replace('Clip 2 — 6 seconds', 'Clip 2 — duration unknown', $base));
        $add('clip_plan_duration_unknown', str_replace('Clip 2 — 6 seconds', 'Clip 3 — 6 seconds', $base));
        $add('clip_plan_duration_mismatch', str_replace('Clip 2 — 6 seconds', 'Clip 2 — 7 seconds', $base));
        $add('speech_map_unparseable', $edit($row, str_replace('0.2–2.2s', 'untimed', $row)));
        $add('speech_map_incomplete', $edit($row, str_replace('SPEAKER: Narrator', 'SPEAKER: ', $row)));
        $add('speech_time_range_invalid', $edit('0.2–2.2s', '-0.2–2.2s'));
        $add('speech_exceeds_clip', $edit('0.2–2.2s', '0.2–7.0s'));
        $add('speech_opening_visual_beat_missing', $edit('0.2–2.2s', '0.0–2.2s'));
        $add('speech_leaves_no_visual_tail', $edit('0.2–2.2s', '0.2–5.9s'));
        $add('speech_rows_overlap', $edit($row, $row."\n".str_replace('0.2–2.2s', '2.1–4.0s', $row)));
        $add('speech_inter_line_pause_missing', $edit($row, $row."\n".str_replace('0.2–2.2s', '2.3–4.2s', $row)));
        $add('speech_overrun', $edit('0.2–2.2s', '0.2–0.5s'));
        $add('speech_budget_exceeded', $edit('It keeps turning.', str_repeat('word ', 30).'.'));
        $silent = str_replace("SPEECH/VISUAL MAP:\n".$row, 'SPEECH/VISUAL MAP: None — spoken audio is off', $base);
        $add('speech_when_disabled', $base, ['narration' => '0'], valid: $silent, validInput: ['narration' => '0']);
        $add('speech_map_empty_unmarked', $edit($row, ''));
        $add('speech_off_marker_mismatch', $silent);
        $add('continuity_audit_overclaim', $base."All handoffs are physically plausible and seamlessly verified.\n");
        $add('timing_audit_overclaim', str_replace('Approximate total runtime: 12 seconds', 'Approximate total runtime: 13 seconds', $base)."All timing representations are synchronized.\n", valid: $base."All timing representations are synchronized.\n");
        $add('speech_pacing_overclaim', $edit('0.2–2.2s', '0.2–0.5s')."All speech fits naturally.\n", valid: $base."All speech fits naturally.\n");
        $b = str_replace('TYPE A — DIRECT CONTINUATION', 'TYPE B — NATURAL ACTION TRANSITION', $base);
        $add('type_b_transition_action_unverified', self::edit($b, 2, 'VISIBLE CAUSE → ACTION → RESULT: The same '.$topic.' turns under a controlled drive.', 'VISIBLE CAUSE → ACTION → RESULT: A clear cause produces one visible action and result.'), valid: $b);
        $c = str_replace('TYPE A — DIRECT CONTINUATION', 'TYPE C — NARRATIVE / TEMPORAL TRANSITION', $base);
        $c = self::edit($c, 2, 'VISIBLE CAUSE → ACTION → RESULT: The same '.$topic.' turns under a controlled drive.', 'VISIBLE CAUSE → ACTION → RESULT: Before state: the '.$topic.' is turning in the workshop. A time-lapse shows the same '.$topic.' turning as time passes. After state: the '.$topic.' continues turning in the workshop.');
        $add('type_c_transition_undocumented', str_replace(['A time-lapse shows', 'as time passes'], ['An unexplained moment shows', 'without temporal evidence'], $c), valid: $c);
        $add('type_c_seamless_claim', self::edit($c, 2, 'PRIMARY IDEA:', "Seamless continuation as if the recording never stopped.\nPRIMARY IDEA:"), valid: $c);
        $hero = self::characters($base);
        $add('canonical_identity_missing', str_replace('CANONICAL IDENTITY CHAR-01: Mira has an oval face, amber eyes and a green coat.', '', $hero), valid: $hero);
        $add('canonical_identity_ambiguous', str_replace('CANONICAL IDENTITY CHAR-01:', "CANONICAL IDENTITY CHAR-01: A different identity.\nCANONICAL IDENTITY CHAR-01:", $hero), valid: $hero);
        $add('canonical_roster_unparseable', str_replace('CANONICAL CHARACTER ROSTER: CHAR-01 ×1', 'CANONICAL CHARACTER ROSTER: Mira', $hero), valid: $hero);
        $add('undeclared_character', self::edit($hero, 2, 'VISIBLE CHARACTER CAST: Mira (CHAR-01) ×1', 'VISIBLE CHARACTER CAST: Unlisted (CHAR-02) ×1'), valid: $hero);
        $add('identity_anchor_unverified', self::edit($hero, 2, 'CHARACTER IDENTITY LOCK: Mira has an oval face, amber eyes and a green coat.', 'CHARACTER IDENTITY LOCK: A person in green.'), valid: $hero);
        $add('cast_count_mismatch', self::edit($hero, 2, 'VISIBLE CHARACTER CAST: Mira (CHAR-01) ×1', 'VISIBLE CHARACTER CAST: Mira (CHAR-01) ×2'), valid: $hero);
        $add('cast_state_conflict', self::edit($hero, 2, 'VISIBLE CHARACTER CAST: Mira (CHAR-01) ×1', 'VISIBLE CHARACTER CAST: None'), valid: $hero);
        return $cases;
    }
}
