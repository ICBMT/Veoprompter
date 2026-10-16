@extends('layouts.app')

@section('title', 'Create project — VeoPrompter')
@section('eyebrow', 'NEW PROJECT')

@section('content')
<div class="project-settings-page">
    <a class="history-back" href="{{ route('projects.index', [], false) }}"><span aria-hidden="true">←</span> Back to projects</a>
    <section class="project-settings-hero"><div><span class="section-index">YOUR WORKSPACE <i>/</i> PROJECT SETUP</span><h1>Create a prompt project</h1><p>Choose the preferences you want to reuse. You can edit them later, and every prompt kit in this project will start with the same creative settings.</p></div><span class="project-settings-mark" aria-hidden="true">✳</span></section>

    <form class="project-settings-form" action="{{ route('projects.store', [], false) }}" method="post">
        @csrf
        @include('components.validation-summary')
        <section class="form-panel project-name-panel">
            <div class="panel-heading"><span class="panel-step">01</span><div><h2>Name your project</h2><p>For example, “Bee garden stories” or “Space explorers”.</p></div></div>
            <label class="field-label" for="name">Project name</label>
            <input class="text-input input-large" id="name" name="name" type="text" maxlength="100" required value="{{ old('name') }}" placeholder="A short name for this prompt project" autofocus>
            @error('name')<p class="admin-error">{{ $message }}</p>@enderror
        </section>

        @include('projects._settings-fields')

        <div class="form-submit-row project-settings-submit">
            <div class="form-footnote"><span class="spark-small">✳</span><span>These are saved project preferences.<br><small>Topic and story details can change with every prompt.</small></span></div>
            <button class="button-primary" type="submit"><span>Create project</span><span class="button-icon" aria-hidden="true">↗</span></button>
        </div>
    </form>
</div>
@endsection
