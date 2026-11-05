"""Render real timed caption pages with FFmpeg/libass; no Whisper or network required."""
import argparse
import hashlib
from pathlib import Path
import re
import shlex
import subprocess
import tempfile

parser = argparse.ArgumentParser()
parser.add_argument('--ffmpeg', required=True)
parser.add_argument('--php-command', default='php')
args = parser.parse_args()
root = Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='subtitle-pages-') as directory:
    directory = Path(directory)
    ass = subprocess.check_output([*shlex.split(args.php_command), str(root / 'scripts/check-subtitle-pagination.php'), '--ass'])
    (directory / 'captions.ass').write_bytes(ass)
    events = re.findall(rb'^Dialogue: 0,([^,]+),([^,]+),', ass, re.M)
    assert len(events) > 1

    def ff(*command):
        return subprocess.check_output([args.ffmpeg, '-nostdin', '-v', 'error', '-threads', '2', *command], cwd=directory, timeout=120, stderr=subprocess.PIPE)

    ff('-y', '-f', 'lavfi', '-i', 'color=c=0x253e50:s=360x640:r=24:d=10', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=10', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', 'original.mp4')
    source_hash = hashlib.sha256((directory / 'original.mp4').read_bytes()).hexdigest()
    ff('-y', '-i', 'original.mp4', '-filter_threads', '2', '-vf', 'ass=captions.ass', '-c:v', 'libx264', '-crf', '0', '-pix_fmt', 'yuv420p', '-fps_mode', 'passthrough', '-c:a', 'copy', 'output.mp4')
    for start, _end in events:
        h, m, s = map(float, start.decode().split(':'))
        rgb = ff('-ss', str(h * 3600 + m * 60 + s + .05), '-i', 'output.mp4', '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-')
        assert len(rgb) == 360 * 640 * 3
        points = [(i // 3 % 360, i // 3 // 360) for i in range(0, len(rgb), 3) if rgb[i] > 170 and rgb[i + 1] > 170]
        assert len(points) > 100, 'caption page is missing'
        assert min(x for x, _ in points) > 10 and max(x for x, _ in points) < 350
        assert max(y for _, y in points) < 630, 'caption page clipped at bottom'
        yellow = sum(rgb[i] > 170 and rgb[i + 1] > 170 and rgb[i + 2] < 90 for i in range(0, len(rgb), 3))
        assert yellow > 10, 'timed highlight is missing'
    tail = ff('-ss', '9.5', '-i', 'output.mp4', '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-')
    assert not any(tail[i] > 170 and tail[i + 1] > 170 for i in range(0, len(tail), 3)), 'captions remained after their timing'
    assert source_hash == hashlib.sha256((directory / 'original.mp4').read_bytes()).hexdigest()
    hashes = [ff('-i', name, '-map', '0:a:0', '-c:a', 'copy', '-f', 'hash', '-hash', 'sha256', '-') for name in ['original.mp4', 'output.mp4']]
    assert hashes[0] == hashes[1]
    print(f'PASS: {len(events)} real caption pages, safe margins, moving highlights, timed removal, unchanged original and copied audio')
