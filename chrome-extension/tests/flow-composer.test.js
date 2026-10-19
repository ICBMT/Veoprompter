import assert from 'node:assert/strict';
import test from 'node:test';
import { flowComposerAction } from '../flow-composer.js';

class FakeEvent {
    constructor(type, options = {}) {
        this.type = type;
        this.bubbles = Boolean(options.bubbles);
        this.inputType = options.inputType;
        this.data = options.data;
    }
}

class FakeInputEvent extends FakeEvent {}

class FakeTextArea {
    constructor(value = '') {
        this._value = value;
        this.disabled = false;
        this.readOnly = false;
        this.className = 'flow-prompt';
        this.events = [];
        this.attributes = {
            placeholder: 'Describe your video prompt',
            role: 'textbox',
        };
    }

    get value() { return this._value; }
    set value(value) { this._value = String(value); }
    getAttribute(name) { return this.attributes[name] ?? null; }
    getBoundingClientRect() { return { width: 280, height: 60 }; }
    focus() {}
    dispatchEvent(event) { this.events.push(event.type); return true; }
}

class FakeInput {}

class FakeContentEditable {
    constructor(text = '') {
        this.innerText = text;
        this.textContent = text;
        this.disabled = false;
        this.readOnly = false;
        this.isContentEditable = true;
        this.className = 'ProseMirror';
        this.events = [];
        this.attributes = { 'aria-label': 'Video prompt', role: 'textbox' };
    }

    getAttribute(name) { return this.attributes[name] ?? null; }
    getBoundingClientRect() { return { width: 280, height: 60 }; }
    focus() {}
    dispatchEvent(event) { this.events.push(event.type); return true; }
    replaceChildren(...nodes) {
        this.innerText = nodes.map((node) => node.textContent).join('');
        this.textContent = this.innerText;
    }
}

function withMockDom(composer, callback) {
    const names = ['document', 'window', 'HTMLInputElement', 'HTMLTextAreaElement', 'InputEvent', 'Event'];
    const previous = new Map(names.map((name) => [name, globalThis[name]]));
    const range = {
        selectNodeContents() {},
        collapse() {},
    };
    const selection = {
        removeAllRanges() {},
        addRange() {},
    };

    globalThis.HTMLInputElement = FakeInput;
    globalThis.HTMLTextAreaElement = FakeTextArea;
    globalThis.InputEvent = FakeInputEvent;
    globalThis.Event = FakeEvent;
    globalThis.window = {
        getComputedStyle: () => ({ display: 'block', visibility: 'visible', opacity: '1' }),
        getSelection: () => selection,
    };
    globalThis.document = {
        querySelectorAll: () => composer ? [composer] : [],
        createRange: () => range,
        createTextNode: (textContent) => ({ textContent }),
        execCommand(command, _showUi, value) {
            if (!(composer instanceof FakeContentEditable)) return false;
            const next = command === 'insertText' ? `${composer.innerText}${value}` : '';
            composer.innerText = next;
            composer.textContent = next;
            return true;
        },
    };

    try {
        callback();
    } finally {
        for (const [name, value] of previous) {
            if (value === undefined) delete globalThis[name];
            else globalThis[name] = value;
        }
    }
}

test('fills an empty textarea and only clears matching unsent text', () => {
    const composer = new FakeTextArea();

    withMockDom(composer, () => {
        assert.deepEqual(flowComposerAction('fill', 'A precise clip prompt.'), { ok: true, result: 'filled' });
        assert.equal(composer.value, 'A precise clip prompt.');
        assert.ok(composer.events.includes('input'));
        assert.ok(composer.events.includes('change'));

        assert.deepEqual(flowComposerAction('clear-if-matching', 'A different prompt.'), {
            ok: false,
            reason: 'composer-changed',
        });
        assert.equal(composer.value, 'A precise clip prompt.');

        assert.deepEqual(flowComposerAction('clear-if-matching', 'A precise clip prompt.'), {
            ok: true,
            result: 'cleared-unsent-text',
        });
        assert.equal(composer.value, '');
    });
});

test('does not overwrite a non-empty composer and supports contenteditable Flow editors', () => {
    const occupied = new FakeTextArea('Keep this user text.');
    withMockDom(occupied, () => {
        assert.deepEqual(flowComposerAction('fill', 'New prompt.'), { ok: false, reason: 'composer-not-empty' });
        assert.equal(occupied.value, 'Keep this user text.');
    });

    const editor = new FakeContentEditable();
    withMockDom(editor, () => {
        assert.deepEqual(flowComposerAction('fill', 'Multi-line prompt\nSecond line.'), { ok: true, result: 'filled' });
        assert.equal(editor.innerText, 'Multi-line prompt\nSecond line.');
        assert.deepEqual(flowComposerAction('clear-if-matching', 'Multi-line prompt\nSecond line.'), {
            ok: true,
            result: 'cleared-unsent-text',
        });
        assert.equal(editor.innerText, '');
    });
});

test('reports when there is no eligible Flow composer', () => {
    withMockDom(null, () => {
        assert.deepEqual(flowComposerAction('fill', 'Prompt.'), { ok: false, reason: 'composer-not-found' });
    });
});
