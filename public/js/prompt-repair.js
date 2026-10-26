(() => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const reportPanel = document.querySelector('[data-repair-report]');

    const safeLocalUrl = (value) => {
        if (typeof value !== 'string' || !value.trim()) return '';
        try {
            const url = new URL(value, window.location.origin);
            return url.origin === window.location.origin ? url.href : '';
        } catch {
            return '';
        }
    };

    const addText = (parent, tag, text, className = '') => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        element.textContent = text;
        parent.append(element);
        return element;
    };

    const issueLabel = (issue) => {
        const message = typeof issue?.message === 'string' ? issue.message.trim() : '';
        if (message) return message;
        const code = typeof issue?.code === 'string' ? issue.code.replaceAll('_', ' ') : 'QA finding';
        return issue?.clip ? `Clip ${issue.clip}: ${code}` : code;
    };

    const renderIssueGroup = (panel, heading, issues) => {
        addText(panel, 'h4', heading);
        const list = document.createElement('ul');
        if (!Array.isArray(issues) || issues.length === 0) {
            addText(list, 'li', 'None', 'repair-report-empty');
        } else {
            issues.forEach((issue) => addText(list, 'li', issueLabel(issue)));
        }
        panel.append(list);
    };

    const renderReport = (report) => {
        if (!reportPanel || !report || typeof report !== 'object') return;
        reportPanel.replaceChildren();
        reportPanel.dataset.kind = 'success';

        const labels = {
            applied: 'Repairs applied',
            applied_with_remaining_issues: 'Safe repairs applied; review remains',
            no_issues: 'No QA issues remain',
            no_supported_issues: 'No additional safe automatic repair is available',
            no_safe_changes: 'No safe changes were made',
            rejected_new_issues: 'A candidate was rejected because it added QA findings',
        };
        addText(reportPanel, 'h3', `Auto-Fix report · ${labels[report.outcome] || 'Repair check complete'}`);
        renderIssueGroup(reportPanel, 'Fixed and verified', report.fixed);
        renderIssueGroup(reportPanel, 'Unresolved', report.unresolved);
        renderIssueGroup(reportPanel, 'New regressions in the saved prompt', report.new_issues);
        if (Array.isArray(report.rejected_candidate_issues) && report.rejected_candidate_issues.length) {
            renderIssueGroup(reportPanel, 'Rejected attempts (candidate not saved)', report.rejected_candidate_issues);
        }
        const informational = Array.isArray(report.informational)
            ? report.informational
            : (Array.isArray(report.notes) ? report.notes : []);
        if (informational.length) {
            addText(reportPanel, 'h4', 'Informational');
            const notes = document.createElement('ul');
            informational.forEach((note) => addText(notes, 'li', String(note)));
            reportPanel.append(notes);
        }
        reportPanel.hidden = false;
    };

    const renderReview = (review) => {
        const panel = document.querySelector('[data-quality-review]');
        if (!panel || !review || typeof review !== 'object') return;
        panel.hidden = false;
        panel.dataset.qualityReviewStatus = review.status || 'not_checked';

        const title = panel.querySelector('[data-quality-review-title]');
        if (title) {
            title.textContent = review.status === 'passed'
                ? 'Automatic text checks passed'
                : review.status === 'needs_review'
                    ? 'Review recommended'
                    : 'Automatic checks could not run';
        }
        const corrected = panel.querySelector('[data-quality-review-corrected]');
        if (corrected) corrected.hidden = review.corrected !== true;

        const checksList = panel.querySelector('[data-quality-review-checks]');
        if (checksList) {
            checksList.replaceChildren();
            (Array.isArray(review.checks) ? review.checks : []).forEach((check) => {
                const item = document.createElement('li');
                item.dataset.checkStatus = check.status || 'not_run';
                addText(item, 'strong', check.label || 'Quality check');
                addText(item, 'span', check.detail || '');
                checksList.append(item);
            });
        }

        const handoffs = Array.isArray(review.handoffs) ? review.handoffs : [];
        const handoffsWrap = panel.querySelector('[data-quality-review-handoffs-wrap]');
        const handoffsList = panel.querySelector('[data-quality-review-handoffs]');
        if (handoffsWrap && handoffsList) {
            handoffsList.replaceChildren();
            handoffs.forEach((handoff) => {
                const type = typeof handoff.type === 'string' ? handoff.type : '?';
                const handoffRelation = handoff.overall_relation || handoff.continuity_relation;
                const relation = handoffRelation === 'exact'
                    ? 'exact state match'
                    : handoffRelation === 'compatible' && type === 'B'
                        ? 'compatible state description, not a verbatim copy'
                        : handoffRelation === 'compatible'
                            ? 'compatible wording'
                            : handoffRelation === 'mismatch'
                                ? 'state mismatch'
                                : 'state not verified';
                const from = Number(handoff.from_clip) || '?';
                const to = Number(handoff.to_clip) || '?';
                addText(handoffsList, 'li', `Clip ${from} → Clip ${to} · Type ${type}: ${relation}`);
            });
            handoffsWrap.hidden = handoffs.length === 0;
        }

        const issues = Array.isArray(review.issues) ? review.issues : [];
        const issuesWrap = panel.querySelector('[data-quality-review-issues-wrap]');
        const issuesList = panel.querySelector('[data-quality-review-issues]');
        if (issuesWrap && issuesList) {
            issuesList.replaceChildren();
            issues.forEach((issue) => addText(issuesList, 'li', issue.message || 'A prompt issue needs review.'));
            issuesWrap.hidden = issues.length === 0;
        }
        const limitations = panel.querySelector('[data-quality-review-limitations]');
        if (limitations) {
            limitations.textContent = Array.isArray(review.limitations)
                ? review.limitations.join(' ')
                : 'Text checks cannot establish factual truth or validate rendered video.';
        }
    };

    const renderOriginal = (content) => {
        if (typeof content !== 'string') return;
        let details = document.querySelector('[data-original-prompt]');
        if (!details) {
            details = document.createElement('details');
            details.className = 'saved-original-prompt';
            details.dataset.originalPrompt = '';
            addText(details, 'summary', 'Compare with the original generated prompt');
            addText(details, 'p', 'This baseline is preserved separately from repairs and remains available for restoration.');
            const outputCard = document.querySelector('[data-repair-output]')?.closest('.saved-output-card');
            if (!outputCard) return;
            outputCard.after(details);
        }
        let pre = details.querySelector('[data-original-prompt-content]');
        if (!pre) {
            pre = document.createElement('pre');
            pre.className = 'history-output saved-original-output';
            pre.dataset.originalPromptContent = '';
            details.append(pre);
        }
        pre.textContent = content;
    };

    const updateControls = (payload) => {
        const repairButton = document.querySelector('[data-prompt-repair]');
        const restoreButton = document.querySelector('[data-prompt-restore]');
        const attempts = Number(payload.manual_attempts) || 0;
        const maxAttempts = Number(payload.max_attempts) || 2;
        const canRepair = payload.can_repair === true;
        const hasQaIssues = Array.isArray(payload.quality_review?.issues) && payload.quality_review.issues.length > 0;

        const attemptLabel = document.querySelector('[data-repair-attempt-count]');
        if (attemptLabel) {
            attemptLabel.textContent = `Automatic repair attempts: ${attempts}/${maxAttempts}`;
        }
        if (repairButton) {
            const repairUrl = safeLocalUrl(payload.repair_url);
            if (repairUrl) repairButton.dataset.repairUrl = repairUrl;
            repairButton.disabled = !canRepair;
            repairButton.hidden = !hasQaIssues;
            repairButton.textContent = attempts >= maxAttempts
                ? 'Repair limit reached'
                : canRepair ? 'Auto-Fix Issues' : 'Manual review needed';
        }
        if (restoreButton) {
            const restoreUrl = safeLocalUrl(payload.restore_url);
            if (restoreUrl) restoreButton.dataset.restoreUrl = restoreUrl;
            restoreButton.hidden = payload.can_restore_original !== true;
        }
    };

    const showError = (message) => {
        if (!reportPanel) return;
        reportPanel.replaceChildren();
        reportPanel.dataset.kind = 'error';
        addText(reportPanel, 'h3', 'Repair could not be completed');
        addText(reportPanel, 'p', message || 'Please reload this saved prompt and try again.');
        reportPanel.hidden = false;
    };

    document.addEventListener('click', async (event) => {
        const button = event.target instanceof Element
            ? event.target.closest('[data-prompt-repair], [data-prompt-restore]')
            : null;
        if (!button || button.disabled) return;

        const isRestore = button.matches('[data-prompt-restore]');
        const url = safeLocalUrl(isRestore ? button.dataset.restoreUrl : button.dataset.repairUrl);
        if (!url) {
            showError('The saved-run action is not available. Reload the page and try again.');
            return;
        }

        const originalLabel = button.textContent;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.textContent = isRestore ? 'Restoring original…' : 'Checking and repairing…';
        if (reportPanel) {
            reportPanel.replaceChildren();
            reportPanel.dataset.kind = 'success';
            addText(reportPanel, 'p', isRestore ? 'Restoring the saved original and rerunning full QA…' : 'Applying only safe repairs, then rerunning full QA…');
            reportPanel.hidden = false;
        }

        let succeeded = false;
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.message || 'The saved prompt could not be updated. Reload and try again.');

            const promptOutput = document.querySelector('[data-repair-output]');
            if (promptOutput && typeof payload.content === 'string') promptOutput.textContent = payload.content;
            renderOriginal(payload.original_content);
            renderReview(payload.quality_review);
            renderReport(payload.repair_report);
            updateControls(payload);
            document.dispatchEvent(new CustomEvent('veoprompter:prompt-repaired', { detail: payload }));
            succeeded = true;
        } catch (error) {
            showError(error instanceof Error ? error.message : 'The saved prompt could not be updated. Reload and try again.');
        } finally {
            button.removeAttribute('aria-busy');
            if (!succeeded && !button.hidden) {
                button.disabled = false;
                button.textContent = originalLabel;
            }
        }
    });
})();
