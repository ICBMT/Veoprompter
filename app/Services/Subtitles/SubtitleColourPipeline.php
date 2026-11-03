<?php

namespace App\Services\Subtitles;

/** Explicit colour conversion before SDR caption compositing; never change the source file. */
final class SubtitleColourPipeline
{
    public static function forStream(array $stream): array
    {
        $transfer = $stream['color_transfer'] ?? '';
        $hdr = in_array($transfer, ['smpte2084', 'arib-std-b67'], true);
        foreach ($stream['side_data_list'] ?? [] as $side) {
            if (isset($side['dv_profile'])) {
                // Only use a Dolby Vision base layer explicitly compatible with HDR10 or HLG.
                $compatibility = (int) ($side['dv_bl_signal_compatibility_id'] ?? 0);
                if (! (($compatibility === 1 && $transfer === 'smpte2084')
                    || ($compatibility === 4 && $transfer === 'arib-std-b67'))) {
                    throw new SubtitleFailure('unsupported_hdr');
                }
            }
        }
        if (! $hdr) {
            return ['source_dynamic_range' => 'sdr', 'source_transfer' => $transfer,
                'filter' => 'ass=captions.ass', 'pixel_format' => $stream['pix_fmt'], 'output_arguments' => []];
        }
        // PQ/HLG delivery is normally BT.2020. Missing tags use that documented fallback;
        // contradictory explicit tags are rejected rather than silently misinterpreted.
        $primaries = $stream['color_primaries'] ?? 'unknown';
        $matrix = $stream['color_space'] ?? 'unknown';
        if (! in_array($primaries, ['bt2020', 'unknown', 'unspecified', ''], true)
            || ! in_array($matrix, ['bt2020nc', 'bt2020c', 'unknown', 'unspecified', ''], true)) {
            throw new SubtitleFailure('unsupported_hdr');
        }
        $matrix = $matrix === 'bt2020c' ? 'bt2020c' : 'bt2020nc';
        $range = ($stream['color_range'] ?? '') === 'pc' ? 'full' : 'limited';
        // Linear-light float processing, BT.709 gamut conversion, then tone mapping.
        // Captions are composited AFTER conversion so white/yellow remain SDR colours.
        $filter = "zscale=pin=bt2020:tin={$transfer}:min={$matrix}:rin={$range}:p=bt2020:t=linear:m=gbr:r=full:npl=100,format=gbrpf32le,"
            .'zscale=p=bt709,tonemap=tonemap=mobius:desat=2:peak=0,'
            .'zscale=t=bt709:m=bt709:r=limited:dither=error_diffusion,format=yuv420p,'
            .'sidedata=mode=delete,ass=captions.ass';

        return ['source_dynamic_range' => 'hdr', 'source_transfer' => $transfer,
            'filter' => $filter, 'pixel_format' => 'yuv420p',
            'output_arguments' => ['-color_primaries', 'bt709', '-color_trc', 'bt709', '-colorspace', 'bt709', '-color_range', 'tv']];
    }
}
