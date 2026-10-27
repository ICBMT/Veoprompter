<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubtitleVideoRequest;
use App\Jobs\ProcessSubtitleVideo;
use App\Models\SubtitleVideo;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubtitleVideoController extends Controller
{
    public function index(Request $request)
    {
        return view('subtitles.index', [
            'videos' => SubtitleVideo::where('user_id', $request->user()->id)->latest()->paginate(12),
            'uploadToken' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreSubtitleVideoRequest $request)
    {
        $video = DB::transaction(function () use ($request): SubtitleVideo {
            // Serialize quota and idempotency checks per owner, even across simultaneous tabs.
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $existing = SubtitleVideo::where('user_id', $request->user()->id)->where('upload_token', $request->validated('upload_token'))->first();
            if ($existing) {
                return $existing;
            }
            if (SubtitleVideo::where('user_id', $request->user()->id)->whereNotIn('status', ['completed', 'failed'])->count() >= config('subtitles.max_active_per_user')) {
                throw ValidationException::withMessages(['video' => 'You already have two videos waiting or processing. Wait for one to finish.']);
            }
            $owned = SubtitleVideo::where('user_id', $request->user()->id);
            if ((clone $owned)->count() >= 20 || (clone $owned)->sum('size_bytes') + $request->file('video')->getSize() > 2147483648) {
                throw ValidationException::withMessages(['video' => 'Your retained upload allowance is full. Contact the administrator or wait for the retention period to expire.']);
            }
            $file = $request->file('video');
            $video = SubtitleVideo::create([
                'user_id' => $request->user()->id,
                'upload_token' => $request->validated('upload_token'),
                'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200),
                'size_bytes' => $file->getSize(),
                'status' => 'uploaded',
                'settings' => $request->safe()->only(['preset', 'language', 'font_size', 'highlight', 'line_length', 'vertical_margin']),
            ]);
            try {
                $file->storeAs($video->directory(), 'original.mp4', 'subtitles');
            } catch (\Throwable $exception) {
                Storage::disk('subtitles')->deleteDirectory($video->directory());
                throw $exception;
            }
            // Dispatch only after the row and original are durable. A failed broker leaves an
            // uploaded row; subtitles:maintain re-dispatches it idempotently.
            ProcessSubtitleVideo::dispatch($video->id)->afterCommit();

            return $video;
        });

        return redirect()->route('subtitles.show', $video);
    }

    public function show(Request $request, SubtitleVideo $subtitleVideo)
    {
        $this->authorizeOwner($request, $subtitleVideo);

        return view('subtitles.show', ['video' => $subtitleVideo]);
    }

    public function status(Request $request, SubtitleVideo $subtitleVideo)
    {
        $this->authorizeOwner($request, $subtitleVideo);

        return response()->json([
            'status' => $subtitleVideo->status,
            'failure' => $subtitleVideo->failureMessage(),
            'preview_url' => $subtitleVideo->status === 'completed' ? route('subtitles.preview', $subtitleVideo, false) : null,
            'download_url' => $subtitleVideo->status === 'completed' ? route('subtitles.download', $subtitleVideo, false) : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, SubtitleVideo $subtitleVideo)
    {
        return $this->output($request, $subtitleVideo, false);
    }

    public function download(Request $request, SubtitleVideo $subtitleVideo)
    {
        return $this->output($request, $subtitleVideo, true);
    }

    private function output(Request $request, SubtitleVideo $video, bool $download)
    {
        $this->authorizeOwner($request, $video);
        abort_unless($video->status === 'completed' && is_file($video->path('output.mp4')), 404);
        $headers = ['Content-Type' => 'video/mp4', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

        // BinaryFileResponse supports authenticated byte ranges for browser seeking.
        return $download
            ? response()->download($video->path('output.mp4'), 'subtitled-'.$video->id.'.mp4', $headers)
            : response()->file($video->path('output.mp4'), $headers);
    }

    private function authorizeOwner(Request $request, SubtitleVideo $video): void
    {
        abort_unless((int) $video->user_id === (int) $request->user()->id, 404);
    }
}
