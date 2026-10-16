@extends('layouts.app')

@section('title', 'Project settings — '.$project->name.' — VeoPrompter')
@section('eyebrow', 'PROJECT SETTINGS')

@section('content')
<div class="project-settings-page">
    <a class="history-back" href="{{ route('projects.show', $project, false) }}"><span aria-hidden="true">←</span> Back to {{ $project->name }}</a>
    <section class="project-settings-hero"><div><span class="section-index">PROJECT <i>/</i> EDIT PREFERENCES</span><h1>Project settings</h1><p>Update the defaults for <strong>{{ $project->name }}</strong>. New prompt kits use your saved settings; previously generated prompts stay unchanged.</p></div><span class="project-settings-mark" aria-hidden="true">✳</span></section>

    <form class="project-settings-form" action="{{ route('projects.settings.update', $project, false) }}" method="post">
        @csrf
        @method('PUT')
        @include('components.validation-summary')
        <section class="form-panel project-name-panel">
            <div class="panel-heading"><span class="panel-step">01</span><div><h2>Project identity</h2><p>Give this creative space a clear name.</p></div></div>
            <label class="field-label" for="name">Project name</label>
            <input class="text-input input-large" id="name" name="name" type="text" maxlength="100" required value="{{ old('name', $project->name) }}">
            @error('name')<p class="admin-error">{{ $message }}</p>@enderror
        </section>

        @include('projects._settings-fields')

        <div class="form-submit-row project-settings-submit">
            <div class="form-footnote"><span class="spark-small">✳</span><span>Only future prompt kits change.<br><small>Your saved generation history remains intact.</small></span></div>
            <button class="button-primary" type="submit"><span>Save project settings</span><span class="button-icon" aria-hidden="true">↗</span></button>
        </div>
    </form>
</div>
@endsection
