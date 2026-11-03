<?php

namespace Tests\Support;

use App\Services\Subtitles\SubtitleColourPipeline;
use App\Services\Subtitles\SubtitleFailure;

final class SubtitleColourCases
{
    public static function run(callable $assert): void
    {
        foreach (['yuv420p', 'yuv420p10le', 'yuv444p10le'] as $format) {
            $sdr = SubtitleColourPipeline::forStream(['pix_fmt' => $format, 'color_transfer' => 'bt709']);
            $assert($sdr['filter'] === 'ass=captions.ass' && $sdr['pixel_format'] === $format && $sdr['output_arguments'] === [], 'SDR path unchanged');
        }
        foreach (['smpte2084', 'arib-std-b67'] as $transfer) {
            foreach (['tv', 'pc'] as $range) {
                $hdr = SubtitleColourPipeline::forStream(['pix_fmt' => 'yuv420p10le', 'color_transfer' => $transfer, 'color_range' => $range]);
                $assert($hdr['source_dynamic_range'] === 'hdr' && $hdr['pixel_format'] === 'yuv420p', 'HDR emits compatible SDR pixel format');
                $assert(str_contains($hdr['filter'], 'tin='.$transfer) && str_contains($hdr['filter'], 'rin='.($range === 'pc' ? 'full' : 'limited')), 'explicit input transfer and range');
                $assert(strpos($hdr['filter'], 'tonemap=') < strpos($hdr['filter'], 'ass='), 'tone mapping precedes captions');
                $assert(in_array('bt709', $hdr['output_arguments'], true) && str_contains($hdr['filter'], 'sidedata=mode=delete'), 'SDR tagging and obsolete HDR side-data removal');
            }
            $compatibility = $transfer === 'smpte2084' ? 1 : 4;
            $compatible = SubtitleColourPipeline::forStream(['pix_fmt' => 'yuv420p10le', 'color_transfer' => $transfer,
                'side_data_list' => [['dv_profile' => 8, 'dv_bl_signal_compatibility_id' => $compatibility]]]);
            $assert($compatible['source_dynamic_range'] === 'hdr', 'Dolby Vision compatible base layer supported');
        }
        foreach ([['color_transfer' => 'smpte2084', 'color_primaries' => 'bt709'],
            ['color_transfer' => 'arib-std-b67', 'color_space' => 'bt709'],
            ['color_transfer' => 'smpte2084', 'side_data_list' => [['dv_profile' => 5, 'dv_bl_signal_compatibility_id' => 0]]],
            ['color_transfer' => 'unknown', 'side_data_list' => [['dv_profile' => 8, 'dv_bl_signal_compatibility_id' => 4]]]] as $invalid) {
            try {
                SubtitleColourPipeline::forStream(['pix_fmt' => 'yuv420p10le', ...$invalid]);
                $assert(false, 'unsupported HDR was accepted');
            } catch (SubtitleFailure $exception) {
                $assert($exception->reason === 'unsupported_hdr', 'unsupported/contradictory colour profile fails explicitly');
            }
        }
    }
}
