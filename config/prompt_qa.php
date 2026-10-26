<?php

return [
    /*
    | Conservative text-only speech estimates used by QA and Auto-Fix.
    | These values do not validate actual voice performance or rendered video.
    */
    'speech' => [
        'words_per_second' => 2.2,
        'sentence_pause_min_seconds' => 0.12,
        'sentence_pause_per_sentence_seconds' => 0.12,
        'sentence_pause_max_seconds' => 0.45,
        'opening_visual_beat_seconds' => 0.2,
        'inter_line_pause_seconds' => 0.2,
        'ending_pause_seconds' => 0.3,
        'handoff_closing_pause_seconds' => 0.3,
        'timing_tolerance_seconds' => 0.05,
    ],
];
