@props(['review' => []])
@php($first = $review['first_pass'] ?? null)
<p class="quality-review-note" data-quality-review-first-pass @if(!is_array($first) || !isset($first['issue_count'])) hidden @endif>
@if(is_array($first) && isset($first['issue_count']))
{{ ($first['stage'] ?? '') === 'serialized_provider_template_before_repair' ? 'First serialized draft' : 'First-pass QA' }}: {{ $first['issue_count'] }} findings ({{ $first['status'] ?? 'not checked' }}).
Final: {{ count($review['issues'] ?? []) }} findings. Newly introduced: {{ isset($review['new_issues']) ? count($review['new_issues']) : 'not measured' }}. Text checks only, not rendered validation.
@endif
</p>
