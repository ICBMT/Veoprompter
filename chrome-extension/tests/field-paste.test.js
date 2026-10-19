import assert from 'node:assert/strict';
import test from 'node:test';
import { armPasteOnNextField } from '../field-paste.js';

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
    constructor(value = '', selectionStart = value.length, selectionEnd = selectionStart) {
        this.nodeType = 1;
        this.tagName = 'TEXTAREA';
        this.type = 'textarea';
        this._value = value;
        this.selectionStart = selectionStart;
        this.selectionEnd = selectionEnd;
        this.disabled = false;
        this.readOnly = false;
        this.isConnected = true;
        this.events = [];
        this.parentElement = null;
    }

    get value() { return this._value; }
    set value(value) { this._value = String(value); }
    getAttribute() { return null; }
    setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; }
    focus() {}
    dispatchEvent(event) { this.events.push(event.type); return true; }
}

class FakeInput {
    constructor(value = '', selectionStart = value.length, selectionEnd = selectionStart) {
        this.nodeType = 1;
        this.tagName = 'INPUT';
        this.type = 'text';
        this._value = value;
        this.selectionStart = selectionStart;
        this.selectionEnd = selectionEnd;
        this.disabled = false;
        this.readOnly = false;
        this.isConnected = true;
        this.events = [];
        this.parentElement = null;
    }

    get value() { return this._value; }
    set value(value) { this._value = String(value); }
    getAttribute() { return null; }
    setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; }
    focus() {}
    dispatchEvent(event) { this.events.push(event.type); return true; }
}

class FakeEditable {
    constructor() {
        this.nodeType = 1;
        this.tagName = 'DIV';
        this.type = '';
        this.innerText = '';
        this.textContent = '';
        this.isContentEditable = true;
        this.isConnected = true;
        this.disabled = false;
        this.readOnly = false;
        this.parentElement = null;
        this.events = [];
    }

    getAttribute(name) { return name === 'contenteditable' ? 'true' : null; }
    contains(element) { return element === this; }
    focus() {}
    dispatchEvent(event) { this.events.push(event.type); return true; }
}

async function withMockDom(callback, pending = {}) {
    const names = ['document', 'window', 'chrome', 'HTMLInputElement', 'HTMLTextAreaElement', 'InputEvent', 'Event'];
    const previous = new Map(names.map((name) => [name, globalThis[name]]));
    const htmlRoot = { nodeType: 1, parentElement: null, getAttribute: () => null, tagName: 'HTML' };
    const listeners = new Set();
    let editable = null;
    const state = {
        activePrompt: {
            id: 'prompt-1',
            nextIndex: 1,
            pendingPaste: { token: 'paste-token', index: 0, tabId: 17, ...pending },
        },
    };
    const range = {
        commonAncestorContainer: htmlRoot,
        selectNodeContents() {},
        collapse() {},
        deleteContents() {},
        insertNode(node) { this.insertedNode = node; },
        setStartAfter() {},
    };
    const selection = {
        rangeCount: 0,
        removeAllRanges() { this.rangeCount = 0; },
        addRange() { this.rangeCount = 1; },
        getRangeAt() { return range; },
    };

    globalThis.HTMLInputElement = FakeInput;
    globalThis.HTMLTextAreaElement = FakeTextArea;
    globalThis.InputEvent = FakeInputEvent;
    globalThis.Event = FakeEvent;
    globalThis.document = {
        documentElement: htmlRoot,
        addEventListener(type, listener) { if (type === 'click') listeners.add(listener); },
        removeEventListener(type, listener) { if (type === 'click') listeners.delete(listener); },
        createRange: () => range,
        createTextNode: (textContent) => ({ textContent }),
        execCommand(_command, _showUi, text) {
            if (!editable) return false;
            editable.innerText += text;
            editable.textContent = editable.innerText;
            return true;
        },
    };
    globalThis.window = { getSelection: () => selection, setTimeout };
    globalThis.chrome = {
        storage: { local: {
            get: async (key) => ({ [key]: structuredClone(state[key]) }),
            set: async (values) => Object.assign(state, structuredClone(values)),
        } },
    };

    try {
        await callback({
            state,
            listeners,
            setEditable: (value) => { editable = value; },
        });
    } finally {
        for (const [name, value] of previous) {
            if (value === undefined) delete globalThis[name];
            else globalThis[name] = value;
        }
    }
}

const waitForPaste = () => new Promise((resolve) => setTimeout(resolve, 10));

test('arms a one-time paste for the next clicked text field and preserves the caret selection', async () => {
    const field = new FakeTextArea('keep replace finish', 5, 12);

    await withMockDom(async ({ state, listeners }) => {
        assert.deepEqual(armPasteOnNextField('copied prompt', 'prompt-1', 0, 'paste-token', 17), { ok: true, armed: true });
        const [listener] = listeners;
        assert.equal(typeof listener, 'function');
        listener({ target: field, composedPath: () => [field] });
        await waitForPaste();

        assert.equal(field.value, 'keep copied prompt finish');
        assert.deepEqual(field.events, ['input']);
        assert.equal(state.activePrompt.pendingPaste, null);
        assert.equal(listeners.size, 0);
    });
});

test('inserts into text inputs at their current cursor', async () => {
    const field = new FakeInput('before after', 7, 7);

    await withMockDom(async ({ listeners }) => {
        armPasteOnNextField('prompt', 'prompt-1', 0, 'paste-token', 17);
        const [listener] = listeners;
        listener({ target: field, composedPath: () => [field] });
        await waitForPaste();

        assert.equal(field.value, 'before promptafter');
        assert.deepEqual(field.events, ['input']);
        assert.equal(field.selectionStart, 13);
        assert.equal(field.selectionEnd, 13);
    });
});

test('leaves unrelated clicks alone and only pastes while the matching arm token is active', async () => {
    const field = new FakeTextArea();
    const unrelated = { nodeType: 1, tagName: 'BUTTON', disabled: false, getAttribute: () => null, parentElement: null };

    await withMockDom(async ({ state, listeners }) => {
        armPasteOnNextField('prompt', 'prompt-1', 0, 'paste-token', 17);
        const [listener] = listeners;
        listener({ target: unrelated, composedPath: () => [unrelated] });
        assert.equal(field.value, '');
        assert.equal(listeners.size, 1);

        state.activePrompt.pendingPaste.token = 'superseded-token';
        listener({ target: field, composedPath: () => [field] });
        await waitForPaste();

        assert.equal(field.value, '');
        assert.equal(listeners.size, 0);
    });
});

test('replaces an older listener when a new part is copied', async () => {
    const field = new FakeTextArea();

    await withMockDom(async ({ state, listeners }) => {
        armPasteOnNextField('first part', 'prompt-1', 0, 'paste-token', 17);
        const [firstListener] = listeners;
        state.activePrompt.nextIndex = 2;
        state.activePrompt.pendingPaste = { token: 'second-token', index: 1, tabId: 17 };
        armPasteOnNextField('second part', 'prompt-1', 1, 'second-token', 17);
        assert.equal(listeners.size, 1);
        const [secondListener] = listeners;
        assert.notEqual(firstListener, secondListener);

        secondListener({ target: field, composedPath: () => [field] });
        await waitForPaste();
        assert.equal(field.value, 'second part');
        assert.equal(listeners.size, 0);
    });
});

test('supports contenteditable fields', async () => {
    const field = new FakeEditable();

    await withMockDom(async ({ setEditable, listeners }) => {
        setEditable(field);
        armPasteOnNextField('contenteditable prompt', 'prompt-1', 0, 'paste-token', 17);
        const [listener] = listeners;
        listener({ target: field, composedPath: () => [field] });
        await waitForPaste();

        assert.equal(field.innerText, 'contenteditable prompt');
    });
});
