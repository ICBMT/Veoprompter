(() => {
    const gsap = window.gsap;
    if (!gsap) return;

    const prefersReducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    if (prefersReducedMotion) return;

    const ScrollTrigger = window.ScrollTrigger;
    if (ScrollTrigger) gsap.registerPlugin(ScrollTrigger);

    const finePointer = window.matchMedia?.('(hover: hover) and (pointer: fine)').matches;
    const visible = (element) => !element.closest('[hidden], [inert]') && getComputedStyle(element).display !== 'none';
    const ease = 'power3.out';

    // 1–9. A short, staggered shell entrance establishes the same motion language on every page.
    const shellIntro = gsap.timeline({ defaults: { ease, duration: 0.46 } });
    shellIntro.from('.brand-mark', { autoAlpha: 0, scale: 0.72, rotation: -16, transformOrigin: '50% 50%' })
        .from('.brand-name', { autoAlpha: 0, x: -9, duration: 0.38 }, '-=0.3')
        .from('.sidebar-caption', { autoAlpha: 0, x: -8, stagger: 0.055, duration: 0.36 }, '-=0.26')
        .from('.nav-link', { autoAlpha: 0, x: -13, stagger: 0.045, duration: 0.36 }, '-=0.27')
        .from('.sidebar-note', { autoAlpha: 0, y: 9, scale: 0.985, duration: 0.42 }, '-=0.2')
        .from('.sidebar-status, .sidebar-account', { autoAlpha: 0, y: 8, stagger: 0.06, duration: 0.4 }, '-=0.19')
        .from('.topbar .breadcrumbs', { autoAlpha: 0, y: -7, duration: 0.32 }, 0.06)
        .from('.topbar-actions > *', { autoAlpha: 0, y: -8, stagger: 0.045, duration: 0.34 }, 0.1);

    // 10–15. Hero moments are tailored to the current page rather than animated as one generic block.
    const studioHero = document.querySelector('.studio-hero');
    if (studioHero) {
        const hero = gsap.timeline({ defaults: { ease, clearProps: 'transform,opacity,visibility' } });
        hero.from('.hero-copy .eyebrow-label', { autoAlpha: 0, y: 12, duration: 0.42 })
            .from('.hero-copy h1', { autoAlpha: 0, y: 23, rotationX: 2, transformPerspective: 900, duration: 0.66, ease: 'power4.out' }, '-=0.2')
            .from('.hero-copy > p, .hero-qualities span', { autoAlpha: 0, y: 12, stagger: 0.055, duration: 0.44 }, '-=0.28')
            .from('.storyboard-card', { autoAlpha: 0, y: 19, z: 14, scale: 0.985, rotationY: -3, rotationX: 1.2, transformPerspective: 1050, duration: 0.66 }, '-=0.38')
            .from('.section-heading-row, .mode-tabs', { autoAlpha: 0, y: 12, stagger: 0.07, duration: 0.42 }, '-=0.24');
    } else {
        const pageHero = document.querySelector('.admin-hero, .history-hero, .guide-hero, .project-settings-hero, .auth-card, .account-hero, .admin-users-hero');
        if (pageHero) {
            const heading = pageHero.querySelector('h1, h2');
            const copy = pageHero.querySelector('p');
            const extras = pageHero.querySelector('.admin-hero-actions, .history-count, .guide-mark, .project-settings-mark');
            const hero = gsap.timeline({ defaults: { ease, clearProps: 'transform,opacity,visibility' } });
            hero.from(pageHero, { autoAlpha: 0, y: 15, duration: 0.54 });
            if (heading) hero.from(heading, { autoAlpha: 0, y: 14, rotationX: 1.5, transformPerspective: 900, duration: 0.55 }, '-=0.34');
            if (copy) hero.from(copy, { autoAlpha: 0, y: 10, duration: 0.42 }, '-=0.3');
            if (extras) hero.from(extras, { autoAlpha: 0, scale: 0.96, y: 9, duration: 0.48 }, '-=0.3');
        }
    }

    // 16–31. One-shot scroll reveals give cards and page sections depth without shifting their layout.
    const revealSelector = [
        '.admin-card', '.admin-method-card', '.admin-prompt-card',
        '.project-settings-grid .form-panel', '.principles-row > div', '.saved-brief-card', '.saved-output-card',
        '.history-empty', '.project-runs-empty', '.project-runs-section', '.wizard-progress',
        '.wizard-navigation', '.voice-preview-card', '.admin-users-page .history-card', '.app-footer',
    ].join(', ');

    const reveal = (element, options = {}) => {
        if (!element || !visible(element)) return;
        const start = {
            autoAlpha: 0,
            y: options.y ?? 17,
            z: options.z ?? 8,
            rotationX: options.rotationX ?? 1.2,
            scale: options.scale ?? 0.99,
            transformPerspective: 1000,
            transformOrigin: 'center bottom',
        };
        const finish = {
            autoAlpha: 1,
            x: 0,
            y: 0,
            z: 0,
            rotationX: 0,
            rotationY: 0,
            scale: 1,
            duration: options.duration ?? 0.52,
            delay: options.delay ?? 0,
            ease: options.ease ?? ease,
            clearProps: 'transform,opacity,visibility',
        };
        const belowFold = element.getBoundingClientRect().top > window.innerHeight * 0.92;
        if (ScrollTrigger && belowFold) {
            finish.scrollTrigger = { trigger: element, start: 'top 91%', once: true };
        }
        gsap.fromTo(element, start, finish);
    };

    document.querySelectorAll(revealSelector).forEach((element, index) => {
        reveal(element, { delay: (index % 4) * 0.035 });
    });

    // 32–37. Related option cards arrive as a calm, readable sequence.
    const staggerSpecs = [
        ['.history-grid', ':scope > .history-card'],
        ['.guide-grid', ':scope > .guide-item'],
        ['.project-runs-list', ':scope > .project-run-card'],
        ['.admin-form-grid', ':scope > .admin-field'],
        ['.project-recipe-list', ':scope > div'],
    ];
    staggerSpecs.forEach(([parentSelector, childSelector]) => {
        document.querySelectorAll(parentSelector).forEach((parent) => {
            const children = [...parent.querySelectorAll(childSelector)].filter(visible);
            if (!children.length) return;
            gsap.fromTo(children,
                { autoAlpha: 0, y: 13, scale: 0.99, transformPerspective: 850 },
                {
                    autoAlpha: 1,
                    y: 0,
                    scale: 1,
                    duration: 0.44,
                    stagger: 0.055,
                    ease,
                    clearProps: 'transform,opacity,visibility',
                    scrollTrigger: ScrollTrigger && parent.getBoundingClientRect().top > window.innerHeight * 0.92
                        ? { trigger: parent, start: 'top 91%', once: true }
                        : undefined,
                },
            );
        });
    });

    // 38–42. Gentle 3D pointer response is reserved for larger surfaces on a mouse or trackpad.
    if (finePointer) {
        const tiltSelector = '.storyboard-card, .history-card, .project-run-card, .admin-card, .admin-method-card, .guide-item, .saved-brief-card, .saved-output-card';
        document.querySelectorAll(tiltSelector).forEach((element) => {
            const rotateX = gsap.quickTo(element, 'rotationX', { duration: 0.42, ease: 'power3.out' });
            const rotateY = gsap.quickTo(element, 'rotationY', { duration: 0.42, ease: 'power3.out' });
            const lift = gsap.quickTo(element, 'z', { duration: 0.42, ease: 'power3.out' });
            element.style.transformPerspective = '1100px';
            element.addEventListener('pointermove', (event) => {
                if (event.pointerType === 'touch') return;
                const bounds = element.getBoundingClientRect();
                const x = (event.clientX - bounds.left) / Math.max(bounds.width, 1) - 0.5;
                const y = (event.clientY - bounds.top) / Math.max(bounds.height, 1) - 0.5;
                rotateX(-y * 3.2);
                rotateY(x * 3.6);
                lift(5);
            });
            element.addEventListener('pointerleave', () => {
                rotateX(0);
                rotateY(0);
                lift(0);
            });
        });
    }

    // 43–46. Magnetic actions, tiny press feedback, and arrow nudges reward interaction without moving content.
    if (finePointer) {
        document.querySelectorAll('.button-primary, .topbar-control').forEach((element) => {
            const moveX = gsap.quickTo(element, 'x', { duration: 0.3, ease: 'power3.out' });
            const moveY = gsap.quickTo(element, 'y', { duration: 0.3, ease: 'power3.out' });
            element.addEventListener('pointermove', (event) => {
                const bounds = element.getBoundingClientRect();
                moveX((event.clientX - (bounds.left + bounds.width / 2)) * 0.035);
                moveY((event.clientY - (bounds.top + bounds.height / 2)) * 0.055);
            });
            element.addEventListener('pointerleave', () => { moveX(0); moveY(0); });
            element.addEventListener('pointerdown', () => gsap.to(element, { scale: 0.975, duration: 0.1, ease: 'power2.out' }));
            element.addEventListener('pointerup', () => gsap.to(element, { scale: 1, duration: 0.22, ease: 'back.out(2.2)' }));
        });

        document.querySelectorAll('.sidebar-note a, .app-footer a, .history-open, .project-edit-inline, .project-run-open, .account-library-link').forEach((link) => {
            const arrow = link.querySelector('span[aria-hidden="true"], .library-link-arrow');
            if (!arrow) return;
            link.addEventListener('pointerenter', () => gsap.to(arrow, { x: 3, duration: 0.22, ease: 'power2.out' }));
            link.addEventListener('pointerleave', () => gsap.to(arrow, { x: 0, duration: 0.28, ease: 'power2.out' }));
        });
    }

    // 47–49. Form focus and choice selection use a small, consistent spring response.
    document.querySelectorAll('.text-input, input:not([type="radio"]):not([type="checkbox"]), select, textarea').forEach((field) => {
        field.addEventListener('focus', () => gsap.to(field, {
            borderColor: '#5b8ca0',
            boxShadow: '0 0 0 3px rgba(111, 195, 222, 0.1)',
            duration: 0.2,
            ease: 'power2.out',
        }));
        field.addEventListener('blur', () => gsap.to(field, {
            boxShadow: '0 0 0 0 rgba(111, 195, 222, 0)',
            duration: 0.2,
            ease: 'power2.out',
        }));
    });

    document.addEventListener('change', (event) => {
        const choice = event.target.closest('.wizard-choice, .aspect-choice, .toggle-choice');
        if (choice && event.target.matches('input[type="radio"], input[type="checkbox"]')) {
            gsap.fromTo(choice, { scale: 0.986 }, { scale: 1, duration: 0.28, ease: 'back.out(2.1)', clearProps: 'transform' });
            const icon = choice.querySelector('.wizard-choice-icon, .ratio-icon, .mode-icon');
            if (icon) gsap.fromTo(icon, { scale: 0.84, rotation: -7 }, { scale: 1, rotation: 0, duration: 0.34, ease: 'back.out(2.5)', clearProps: 'transform' });
        }
    });

    // 50–53. The wizard animates each newly active question, including conditional steps.
    const animateActiveWizardStep = (step) => {
        if (!step || step.dataset.motionActive === 'true' || !visible(step)) return;
        step.dataset.motionActive = 'true';
        gsap.fromTo(step.querySelector('.wizard-question') || step,
            { autoAlpha: 0, y: 18, rotationX: 1.2, transformPerspective: 900 },
            { autoAlpha: 1, y: 0, rotationX: 0, duration: 0.46, ease, clearProps: 'transform,opacity,visibility' },
        );
        const answers = [...step.querySelectorAll('.wizard-answer, .wizard-choice, .wizard-review-list > div')].filter(visible);
        if (answers.length) {
            gsap.fromTo(answers,
                { autoAlpha: 0, y: 11, scale: 0.99 },
                { autoAlpha: 1, y: 0, scale: 1, duration: 0.36, stagger: 0.04, delay: 0.08, ease, clearProps: 'transform,opacity,visibility' },
            );
        }
        step.dataset.motionActive = 'false';
    };

    const wizard = document.querySelector('[data-project-wizard]');
    if (wizard) {
        let currentStep = wizard.querySelector('[data-wizard-step][aria-current="step"]');
        animateActiveWizardStep(currentStep);
        const stepObserver = new MutationObserver(() => {
            const nextStep = wizard.querySelector('[data-wizard-step][aria-current="step"]');
            if (!nextStep || nextStep === currentStep || nextStep.hidden) return;
            currentStep = nextStep;
            currentStep.dataset.motionActive = 'false';
            animateActiveWizardStep(currentStep);
        });
        wizard.querySelectorAll('[data-wizard-step]').forEach((step) => stepObserver.observe(step, {
            attributes: true,
            attributeFilter: ['hidden', 'aria-current'],
        }));
    }

    // 54–56. Newly generated prompt results and audio players receive their own small entrance.
    document.querySelectorAll('[data-results]').forEach((results) => {
        const resultsObserver = new MutationObserver(() => {
            if (results.hidden) return;
            const targets = results.querySelectorAll('.results-header, .result-meta, .output-content, .generated-voice-preview');
            gsap.fromTo(targets,
                { autoAlpha: 0, y: 12 },
                { autoAlpha: 1, y: 0, duration: 0.42, stagger: 0.06, ease, clearProps: 'transform,opacity,visibility' },
            );
            resultsObserver.disconnect();
        });
        resultsObserver.observe(results, { attributes: true, attributeFilter: ['hidden'] });
    });
    window.addEventListener('veo:voice-sample-ready', (event) => {
        const player = event.detail?.player;
        if (player) gsap.fromTo(player, { autoAlpha: 0, y: 7, scale: 0.985 }, { autoAlpha: 1, y: 0, scale: 1, duration: 0.36, ease, clearProps: 'transform,opacity,visibility' });
    });

    // 57–59. Empty states, connection lights, and the storyboard art keep the rainy-sky atmosphere alive.
    const emptyOrbit = document.querySelector('.empty-orbit');
    if (emptyOrbit) {
        gsap.to(emptyOrbit, { rotation: 360, duration: 28, repeat: -1, ease: 'none', transformOrigin: '50% 50%' });
        gsap.to('.empty-orbit i', { scale: 1.16, opacity: 0.58, duration: 2.2, repeat: -1, yoyo: true, ease: 'sine.inOut' });
        gsap.to('.empty-orbit b', { scale: 0.78, opacity: 0.72, duration: 1.7, repeat: -1, yoyo: true, ease: 'sine.inOut', delay: 0.35 });
    }
    gsap.to('.status-orb, .status-dot.dot-ready', { scale: 1.2, opacity: 0.76, duration: 1.25, repeat: -1, yoyo: true, ease: 'sine.inOut', stagger: 0.18 });

    if (studioHero && document.querySelector('.storyboard-art')) {
        const atmosphere = gsap.timeline({ repeat: -1, yoyo: true, defaults: { ease: 'sine.inOut' } });
        atmosphere.to('.art-sun', { x: 3, y: -5, duration: 4.1 })
            .to('.art-planet', { x: -2, y: 6, duration: 5.1 }, 0)
            .to('.art-character', { y: -2.5, duration: 2.5 }, 0)
            .to('.art-orbit--two', { rotation: 5, duration: 5.5 }, 0);
        gsap.to('.art-stars', { backgroundPosition: '11px 27px, 40px 21px', duration: 7.5, repeat: -1, yoyo: true, ease: 'sine.inOut' });
    }

    // Keep scroll-triggered reveals aligned after fonts and late-loaded reference art settle.
    if (ScrollTrigger) {
        const refresh = () => ScrollTrigger.refresh();
        if (document.fonts?.ready) document.fonts.ready.then(refresh);
        window.addEventListener('load', refresh, { once: true });
    }
})();
