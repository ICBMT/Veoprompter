<?php

namespace App\Http\Controllers;

use App\Jobs\RenderSubtitleVideo;
use App\Models\SubtitleVideo;
use App\Services\Subtitles\AssSubtitleGenerator;
use App\Services\Subtitles\SubtitleFailure;
use App\Services\Subtitles\TranscriptReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubtitleReviewController extends Controller
{
    public function show(Request $request, SubtitleVideo $subtitleVideo)
    {
        $this->owner($request, $subtitleVideo);
        $this->notExpired($subtitleVideo);
        abort_unless(is_array($subtitleVideo->transcript_source) && is_array($subtitleVideo->transcript_draft), 409, 'The transcript is not ready for review.');
        $draft = $subtitleVideo->transcript_approved ?? $subtitleVideo->transcript_draft;
        $words = [];
        foreach ($subtitleVideo->transcript_source as $i => $word) {
            $words[] = ['start' => $word['start'], 'end' => $word['end'], 'original' => $word['word'], 'text' => $draft[$i]['word']];
        }

        return response()->json(['status' => $subtitleVideo->status, 'words' => $words, 'revision' => $subtitleVideo->transcript_revision,
            'editable' => $subtitleVideo->status === 'awaiting_review', 'approved_at' => $subtitleVideo->approved_at?->toIso8601String()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, SubtitleVideo $subtitleVideo, TranscriptReview $review)
    {
        return $this->update($request, $subtitleVideo, $review, false);
    }

    public function approve(Request $request, SubtitleVideo $subtitleVideo, TranscriptReview $review)
    {
        return $this->update($request, $subtitleVideo, $review, true);
    }

    private function update(Request $request, SubtitleVideo $video, TranscriptReview $review, bool $approve)
    {
        $this->owner($request, $video);
        $rules = ['revision' => ['required', 'integer', 'min:1'], 'words' => ['required', 'array', 'list', 'min:1', 'max:'.TranscriptReview::limit()],
            'words.*' => ['required', 'string', 'max:120']];
        if ($approve) {
            $rules['confirmed'] = ['required', 'accepted'];
        }
        $data = $request->validate($rules);
        try {
            $result = DB::transaction(function () use ($request, $video, $review, $approve, $data) {
                $locked = SubtitleVideo::whereKey($video->id)->lockForUpdate()->firstOrFail();
                $this->owner($request, $locked);
                $this->notExpired($locked);
                $words = $review->replace($locked->transcript_source ?? [], $data['words']);
                $hash = TranscriptReview::hash($words);
                // Safe retry after a lost response, including edits approved in the original request.
                if ($approve && $locked->hasApprovedTranscript() && hash_equals($locked->approved_hash, $hash)) {
                    return ['status' => $locked->status, 'revision' => $locked->transcript_revision, 'approved_at' => $locked->approved_at->toIso8601String()];
                }
                abort_unless($locked->status === 'awaiting_review', 409, 'This transcript is no longer editable. Reload to see its current state.');
                abort_unless($locked->transcript_revision === (int) $data['revision'], 409, 'A newer draft was saved in another tab. Your edits were not applied. Reload and review the latest draft.');
                $revision = $locked->transcript_revision + (TranscriptReview::hash($locked->transcript_draft) === $hash ? 0 : 1);
                if ($approve) {
                    // Layout validation is not a video render: reject unrenderable text before freezing approval.
                    app(AssSubtitleGenerator::class)->generate(TranscriptReview::forAss($words), $locked->settings,
                        (int) $locked->media['width'], (int) $locked->media['height'], (float) $locked->media['duration']);
                    $locked->update(['transcript_draft' => $words, 'transcript_revision' => $revision,
                        'transcript_approved' => $words, 'approved_revision' => $revision, 'approved_hash' => $hash,
                        'approved_by' => $request->user()->id, 'approved_at' => now(), 'status' => 'approved']);
                    RenderSubtitleVideo::dispatch($locked->id)->afterCommit();
                } else {
                    $locked->update(['transcript_draft' => $words, 'transcript_revision' => $revision]);
                }

                return ['status' => $locked->status, 'revision' => $revision, 'approved_at' => $locked->approved_at?->toIso8601String()];
            });
        } catch (SubtitleFailure $exception) {
            throw ValidationException::withMessages(['words' => $exception->reason === 'sentence_too_long'
                ? 'The corrected captions do not fit safely. Add appropriate sentence punctuation or shorten an overlong replacement before approving.'
                : 'Keep every timed word in order, use nonempty text of at most 120 characters per row, and do not insert line breaks or control characters.']);
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function original(Request $request, SubtitleVideo $subtitleVideo)
    {
        $this->owner($request, $subtitleVideo);
        abort_unless(is_file($subtitleVideo->path('original.mp4')), 404);

        return response()->file($subtitleVideo->path('original.mp4'), [
            'Content-Type' => 'video/mp4', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function notExpired(SubtitleVideo $video): void
    {
        abort_if($video->created_at->lte(now()->subDays(max(1, (int) config('subtitles.retention_days')))), 410,
            'This video has reached its retention deadline. Upload again to transcribe and review a fresh copy.');
    }

    private function owner(Request $request, SubtitleVideo $video): void
    {
        abort_unless((int) $video->user_id === (int) $request->user()->id, 404);
    }
}
