"""Real PQ/HLG/SDR render checks using the production PHP colour plan. No Whisper/network.
python3 scripts/check-subtitle-hdr.py --ffmpeg /path/ffmpeg --ffprobe /path/ffprobe --php-command php
"""
import argparse
import hashlib
import json
from pathlib import Path
import shlex
import subprocess
import tempfile

parser = argparse.ArgumentParser()
parser.add_argument('--ffmpeg', required=True)
parser.add_argument('--ffprobe', required=True)
parser.add_argument('--php-command', default='php')
args = parser.parse_args()
root = Path(__file__).resolve().parent.parent
results = []
with tempfile.TemporaryDirectory(prefix='subtitle-hdr-') as temporary:
    for name, transfer, full, rotated in [('sdr', 'bt709', False, False), ('pq', 'smpte2084', False, False), ('hlg', 'arib-std-b67', False, False), ('pq-full', 'smpte2084', True, False), ('hlg-rotated', 'arib-std-b67', False, True)]:
        directory = Path(temporary) / name
        directory.mkdir()

        def run(*command):
            return subprocess.check_output(command, cwd=directory, timeout=120, stderr=subprocess.PIPE)

        def ff(*command):
            try:
                return run(args.ffmpeg, '-nostdin', '-v', 'error', '-threads', '2', *command)
            except subprocess.CalledProcessError as error:
                raise RuntimeError(error.stderr.decode()[-5000:]) from error

        def probe(file):
            return json.loads(run(args.ffprobe, '-v', 'error', '-show_streams', '-show_format', '-of', 'json', file))

        hdr = transfer != 'bt709'
        video = ['-c:v', 'libx264', '-pix_fmt', 'yuv420p']
        if hdr:
            x265 = 'pools=1:frame-threads=1:log-level=error'
            if transfer == 'smpte2084':
                x265 += ':hdr10=1:master-display=G(13250,34500)B(7500,3000)R(34000,16000)WP(15635,16450)L(10000000,1):max-cll=1000,400'
            conversion = 'zscale=pin=bt709:tin=bt709:min=bt709:rin=limited:p=bt709:t=linear:m=gbr:r=full:npl=100,format=gbrpf32le,'+f'zscale=p=bt2020:t={transfer}:m=bt2020nc:r={"full" if full else "limited"},format=yuv420p10le'
            video = ['-filter_threads', '2', '-vf', conversion, '-c:v', 'libx265', '-preset', 'ultrafast', '-x265-params', x265, '-tag:v', 'hvc1', '-pix_fmt', 'yuv420p10le', '-color_primaries', 'bt2020', '-color_trc', transfer, '-colorspace', 'bt2020nc', '-color_range', 'pc' if full else 'tv']
        ff('-y', '-f', 'lavfi', '-i', 'testsrc2=size=360x640:rate=24:duration=4', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=4', *video, '-c:a', 'aac', '-shortest', 'base.mp4')
        if rotated:
            ff('-y', '-display_rotation:v:0', '90', '-i', 'base.mp4', '-c', 'copy', 'original.mp4')
        else:
            (directory / 'base.mp4').rename(directory / 'original.mp4')
        original_hash = hashlib.sha256((directory / 'original.mp4').read_bytes()).hexdigest()
        source = probe('original.mp4')['streams'][0]
        plan = json.loads(run(*shlex.split(args.php_command), str(root / 'scripts/check-subtitle-colour.php'), json.dumps(source)))
        width, height = (640, 360) if rotated else (360, 640)
        ass = run(*shlex.split(args.php_command), str(root / 'scripts/subtitle-ass-smoke.php'), str(width), str(height))
        (directory / 'captions.ass').write_bytes(ass)
        ff('-y', '-i', 'original.mp4', '-map', '0:v:0', '-map', '0:a:0', '-filter_threads', '2', '-vf', plan['filter'], '-c:v', 'libx264', '-preset', 'medium', '-crf', '0', '-pix_fmt', plan['pixel_format'], '-fps_mode', 'passthrough', '-c:a', 'copy', *plan['output_arguments'], '-movflags', '+faststart', '-map_metadata', '-1', 'output.mp4')
        output = probe('output.mp4')['streams'][0]
        assert (output['width'], output['height']) == (width, height), output
        assert output['pix_fmt'] == 'yuv420p', output
        assert output['nb_frames'] == source['nb_frames'] and output['avg_frame_rate'] == source['avg_frame_rate']
        if hdr:
            assert output['color_transfer'] == output['color_primaries'] == output['color_space'] == 'bt709', output
            assert output['color_range'] == 'tv', output
            assert not any(any(label in side.get('side_data_type', '').lower() for label in ['mastering', 'content light', 'dovi', 'hdr']) for side in output.get('side_data_list', [])), output
        assert original_hash == hashlib.sha256((directory / 'original.mp4').read_bytes()).hexdigest()
        audio_hashes = [ff('-i', file, '-map', '0:a:0', '-c:a', 'copy', '-f', 'hash', '-hash', 'sha256', '-') for file in ['original.mp4', 'output.mp4']]
        assert audio_hashes[0] == audio_hashes[1]
        # Captions must be composited, not just an output file with corrected tags.
        rgb = ff('-ss', '0.5', '-i', 'output.mp4', '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-')
        assert len(rgb) == width * height * 3
        bare_filter = plan['filter'].removesuffix(',ass=captions.ass') if hdr else 'null'
        bare = ff('-ss', '0.5', '-i', 'original.mp4', '-frames:v', '1', '-filter_threads', '2', '-vf', bare_filter, '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-')
        bottom = width * (height // 2) * 3
        changed = sum(rgb[i:i + 3] != bare[i:i + 3] for i in range(bottom, len(rgb), 3))
        assert changed > 100, 'Caption pixels were not rendered'
        results.append({'input': name, 'output': 'SDR BT.709' if hdr else 'SDR unchanged path', 'dimensions': f'{width}x{height}', 'frames': output['nb_frames'], 'original_unchanged': True, 'audio_packet_hash_equal': True})
print(json.dumps(results, indent=2))
