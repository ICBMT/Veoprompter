"""Real FFmpeg/libass smoke. Usage: python3 scripts/check-subtitle-render.py --ffmpeg /usr/bin/ffmpeg
Optional: --php-command 'npx --yes --package=@php-wasm/cli@3.1.60 php-wasm-cli'
No transcription/model download. Temp files are removed automatically. No third-party Python dependencies.
"""
import argparse
import hashlib
import json
from pathlib import Path
import shlex
import subprocess
import tempfile

parser = argparse.ArgumentParser()
parser.add_argument('--ffmpeg', default='/usr/bin/ffmpeg')
parser.add_argument('--php-command', default='php')
args = parser.parse_args()
root = Path(__file__).resolve().parent.parent
results = []
with tempfile.TemporaryDirectory(prefix='subtitle-render-') as temporary:
    for width, height in [(720, 1280), (360, 640), (1280, 720)]:
        directory = Path(temporary) / f'{width}x{height}'
        directory.mkdir()
        ass = subprocess.check_output([*shlex.split(args.php_command), '-f', str(root / 'scripts/subtitle-ass-smoke.php'), '--', str(width), str(height)], cwd=root)
        assert ass.startswith(b'[Script Info]'), ass
        (directory / 'captions.ass').write_bytes(ass)

        def run(*arguments):
            return subprocess.check_output([args.ffmpeg, '-nostdin', '-v', 'error', *arguments], cwd=directory, timeout=120)

        run('-y', '-f', 'lavfi', '-i', f'color=c=0x253e50:s={width}x{height}:r=24:d=4', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=4', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', 'original.mp4')
        original_hash = hashlib.sha256((directory / 'original.mp4').read_bytes()).hexdigest()
        run('-y', '-protocol_whitelist', 'file', '-f', 'mov', '-i', 'original.mp4', '-vf', 'ass=captions.ass', '-c:v', 'libx264', '-crf', '0', '-preset', 'medium', '-pix_fmt', 'yuv420p', '-fps_mode', 'passthrough', '-c:a', 'copy', '-movflags', '+faststart', 'output.mp4')
        assert original_hash == hashlib.sha256((directory / 'original.mp4').read_bytes()).hexdigest()
        audio_hashes = [run('-i', name, '-map', '0:a:0', '-c:a', 'copy', '-f', 'hash', '-hash', 'sha256', '-') for name in ['original.mp4', 'output.mp4']]
        assert audio_hashes[0] == audio_hashes[1]
        frames = []
        for timestamp in ['0.5', '0.9', '2.3', '3.0']:
            rgb = run('-ss', timestamp, '-i', 'output.mp4', '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-')
            assert len(rgb) == width * height * 3
            min_x, min_y, max_x, max_y = width, height, 0, 0
            yellow, yellow_y = 0, 0
            for offset in range(0, len(rgb), 3):
                r, g, b = rgb[offset:offset + 3]
                if r > 170 and g > 170:
                    x, y = (offset // 3) % width, (offset // 3) // width
                    min_x, min_y, max_x, max_y = min(min_x, x), min(min_y, y), max(max_x, x), max(max_y, y)
                    if b < 90:
                        yellow += 1
                        yellow_y += y
            assert yellow > 20
            assert min_x > 10 and max_x < width - 10 and max_y < height - 10
            frames.append({'time': timestamp, 'bounds': [min_x, min_y, max_x, max_y], 'highlight_y': round(yellow_y / yellow, 1)})
        for frame in frames:
            assert all(abs(a - b) <= 2 for a, b in zip(frame['bounds'], frames[0]['bounds'])), frames
        assert frames[2]['highlight_y'] > frames[0]['highlight_y'] + 5
        results.append({'resolution': f'{width}x{height}', 'stable_bounds': True, 'original_unchanged': True, 'audio_packet_hash_equal': True, 'frames': frames})
print(json.dumps(results, indent=2))
