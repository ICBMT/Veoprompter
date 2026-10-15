@extends('layouts.app')

@section('title', 'The continuity method — VeoPrompter')
@section('eyebrow', 'CONTINUITY GUIDE')

@section('content')
<div class="guide-page">
    <section class="guide-hero">
        <div class="guide-hero-copy"><span class="section-index">A BETTER PROMPT IS A BETTER HANDOFF</span><h1>Continuity is a craft.<br><em>Not a lucky accident.</em></h1><p>VeoPrompter turns your brief into a production-ready story plan. Every clip carries its own visual rules and starts from a precise frame, so you can copy prompts directly into Google Flow.</p></div>
        <div class="guide-mark" aria-hidden="true"><svg viewBox="0 0 64 64"><circle cx="32" cy="32" r="23"/><circle cx="32" cy="32" r="15"/><circle cx="32" cy="32" r="6"/><path d="M32 9V3m0 58v-6M9 32H3m58 0h-6M16 16l-4-4m40 40-4-4m0-32 4-4M12 52l4-4"/><path d="M46 18c-4 3-7 8-7 14s3 11 7 14"/></svg></div>
    </section>

    <section class="guide-grid" aria-label="How the prompting method works">
        <article class="guide-item"><span>01 / CANONICAL DESIGN</span><h2>Lock the character first</h2><p>Each new story starts with a detailed character bible and a copyable reference-image prompt. Fixed colours, markings, proportions, clothing, and voice give every later clip a stable point of reference.</p></article>
        <article class="guide-item"><span>02 / ONE COHERENT WORLD</span><h2>Keep the scene grounded</h2><p>The world and object bible records the environment, props, lighting, palette, and camera language. Nothing quietly changes position or design between shots.</p></article>
        <article class="guide-item"><span>03 / VISIBLE CAUSE &amp; EFFECT</span><h2>Give each clip one job</h2><p>A focused learning beat fits the clip duration. Narration is timed to what viewers can see, and every action is concrete enough to stage and follow.</p></article>
        <article class="guide-item"><span>04 / EXACT FRAME HANDOFFS</span><h2>Continue—don't restart</h2><p>Each ending frame becomes the next opening frame. Position, pose, gaze, camera, lighting, and motion are spelled out, with the required continuation instruction repeated inside each prompt.</p></article>
        <article class="guide-item"><span>05 / COPY IT ONCE</span><h2>Prompts that stand alone</h2><p>Each clip prompt repeats the details a video model needs. Copy one section on its own—no hidden memory, “same as above,” or manual prompt assembly.</p></article>
        <article class="guide-item guide-item--highlight"><span>06 / HONEST EXPECTATIONS</span><h2>Better consistency, not a guarantee</h2><p>Good instructions improve reliability, but a video model can still vary. When available, use the character reference image and the preceding clip's final frame as Flow inputs.</p></article>
    </section>

    <blockquote class="guide-quote">“The goal is not impressive-sounding prose. The goal is prompts a video model can follow reliably.”<small>THE VEO PROMPTER QUALITY STANDARD</small></blockquote>

    <div class="guide-cta"><div><strong>Ready to shape a story?</strong><p>Bring a topic or final frame. We'll build the continuity plan around it.</p></div><a class="button-primary" href="{{ route('studio', [], false) }}"><span>Open prompt studio</span><span class="button-icon" aria-hidden="true">↗</span></a></div>
</div>
@endsection
