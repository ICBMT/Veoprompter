@extends('layouts.app')
@section('title', 'Today’s video ideas — VeoPrompter')
@section('eyebrow', 'IDEA DISCOVERY')
@push('styles')<link rel="stylesheet" href="/css/video-ideas.css">@endpush
@push('scripts')<script type="module" src="/js/video-ideas.js"></script>@endpush
@section('content')
<section class="history-hero"><div><span class="section-index">LIVE SEARCH SIGNALS / CREATIVE STARTING POINTS</span><h1>What could you create today?</h1><p>Ten AI video ideas informed by today’s Google search trends in your chosen region. These are creative suggestions—not a ranking of popular videos or a guarantee of views. Check the facts behind news topics before publishing.</p></div></section>
@include('components.validation-summary')
<form action="{{ route('ideas.generate', [], false) }}" method="post" class="ideas-controls" data-ideas-form>
    @csrf
    <input type="hidden" name="request_id" value="{{ old('request_id', (string) \Illuminate\Support\Str::uuid()) }}">
    <label>Trend region<select class="text-input" name="region" required>@foreach($regions as $code => $region)<option value="{{ $code }}" @selected(old('region', 'PK') === $code)>{{ $region[0] }}</option>@endforeach</select></label>
    <label>Video format<select class="text-input" name="video_type" required>@foreach($videoTypes as $type => $label)<option value="{{ $type }}" @selected(old('video_type', 'educational') === $type)>{{ $label }}</option>@endforeach</select></label>
    <label>Audience<input class="text-input" name="audience" maxlength="160" required value="{{ old('audience', 'Curious children and families') }}"></label>
    <button class="button-primary" type="submit" @disabled(!$aiReady)>Find ten video ideas</button>
    <p data-ideas-feedback role="status">@if(!$aiReady)An administrator needs to configure the AI connection first.@else Uses your configured AI provider. Live evidence is refreshed at most every 15 minutes; no research runs until you click.@endif</p>
</form>
@if($batch)
<section aria-label="Generated ideas">
    <h2>Your ten creative starting points</h2>
    <p>Evidence date: <strong>{{ $batch['feed']['date'] }}</strong> · {{ $regions[$batch['feed']['region']][0] }} · {{ $batch['feed']['timezone'] }}. {{ $batch['feed']['source'] }} fetched {{ $batch['feed']['fetched_at'] }}. Ideas generated {{ $batch['generated_at'] }}.</p>
    <p>Based on {{ count($batch['feed']['evidence']) }} current-day search topics. Multiple creative angles may share a topic. Earlier results stay visible if a new request fails. Ideas expire after three hours; their displayed evidence date does not change.</p>
    <div class="ideas-grid">@foreach($batch['ideas'] as $index => $idea)
        <article class="idea-card"><span class="section-index">IDEA {{ $index + 1 }} / {{ $videoTypes[$idea['video_type']] }}</span><h3>{{ $idea['title'] }}</h3><p><strong>Hook:</strong> {{ $idea['hook'] }}</p><p>{{ $idea['angle'] }}</p>
            <p class="idea-source">Search-topic evidence: <a href="{{ $idea['source']['url'] }}" target="_blank" rel="noopener noreferrer">{{ $idea['source']['topic'] }} ↗</a> · {{ $idea['source']['published_at'] }}</p>
            <details><summary>Suggested answers for this video format</summary><dl>@foreach(['topic' => 'Topic / premise', 'learning_objective' => 'Learning objective', 'creative_direction' => 'Format-specific direction', 'character_idea' => 'Character / object / visual motif'] as $field => $label)@if(!empty($idea['answers'][$field]))<dt>{{ $label }}</dt><dd>{{ $idea['answers'][$field] }}</dd>@endif @endforeach</dl></details>
            <form action="{{ route('ideas.choose', [], false) }}" method="post">@csrf<input type="hidden" name="batch_id" value="{{ $batch['id'] }}"><input type="hidden" name="index" value="{{ $index }}"><button class="button-secondary" type="submit">Review in project wizard →</button></form>
        </article>
    @endforeach</div>
</section>
@endif
<p class="idea-footer">Next: review the project wizard → edit the suggested creative answers → generate your prompt kit → use the existing <strong>Send to Flow</strong> button. Only a finished prompt kit goes to the extension. Nothing is submitted to Flow automatically.</p>
@endsection
