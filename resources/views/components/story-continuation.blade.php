@props(['run' => null, 'compact' => false])
<section class="story-continuation" data-story-panel @if(!$run) hidden @endif>
    <details @if(!$compact) open @endif>
        <summary data-story-heading>@if($run)Part {{ $run->story_part }} · Batch {{ $run->story_batch }} · {{ $run->story_status === 'open' ? 'Story ongoing' : 'Part complete — create a sequel' }}@else Continue this story @endif</summary>
        <p class="field-hint">Keep the story, characters and visual world connected. Each request saves a new kit; it never replaces this one. You can grow either format one batch at a time.</p>
        @if(config('prompt_generation.continuation_identity_mode', 'ai_managed') === 'ai_managed')
            <p class="field-hint">AI-managed identity continuity uses the complete saved prompt. Identity findings are advisory; review the generated characters and objects before rendering. {{ config('prompt_generation.continuation_boundary_mode', 'advisory') === 'advisory' ? 'Suspected opening/handoff differences are saved for your review, not rejected automatically.' : 'Strict opening-state checks are enabled.' }}</p>
        @endif
        @if(\App\Services\StoryContinuationService::optionalMemory())
            <p class="field-hint">The complete saved prompt carries the story forward. Part/batch status is managed by the app; a generated JSON story-memory footer is not required.</p>
        @endif
        <form method="post" action="{{ $run ? route('history.continue', $run, false) : '' }}" data-story-form data-story-status="{{ $run?->story_status ?? 'ended' }}">
            @csrf
            <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <label class="field-label">How much should we generate?
                <select class="text-input select-input" name="batch_size"><option value="set">A set of clips · saved count preference</option><option value="single">One next clip</option></select>
            </label>
            <label class="field-label">What should happen next? <span class="field-hint">Optional</span>
                <textarea class="text-input" name="direction" rows="3" maxlength="4000" placeholder="For example: follow the same characters into the observatory, reveal the clue slowly, and keep the mystery open."></textarea>
            </label>
            <label class="field-hint"><input type="checkbox" name="allow_new_subjects" value="1"> Allow new recurring subjects explicitly described above (instruct the AI to preserve existing identities and project restrictions).</label>
            <div class="story-actions">
                <button class="button-primary" type="submit" name="intent" value="continue" data-story-intent="continue" @if(!$run || $run->story_status !== 'open') hidden @endif>Generate next clips</button>
                <button class="button-secondary" type="submit" name="intent" value="finish" data-story-intent="finish" @if(!$run || $run->story_status !== 'open') hidden @endif>Generate ending · end story</button>
                <button class="button-primary" type="submit" name="intent" value="sequel" data-story-intent="sequel" @if($run && $run->story_status === 'open') hidden @endif>Create Part {{ $run ? $run->story_part + 1 : 2 }}</button>
            </div>
            <p class="field-hint">Generating an ending closes this part. Create the next part whenever you want; the new part can continue in more batches.</p>
            <p class="story-request-status" data-story-message role="status" aria-live="polite"></p>
        </form>
    </details>
</section>
