import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
const source = readFileSync(new URL('../../public/js/site-motion.js', import.meta.url), 'utf8');

test('quick scale setters reset individual transform axes, never GSAP scale shorthand', () => {
    assert.doesNotMatch(source, /quickTo\([^\n]*,\s*['"]scale['"]/);
    const body = source.match(/const quickScaleTo = \(element, options\) => \{[\s\S]*?\n    \};/)[0];
    const calls = [];
    const context = { gsap: { quickTo(element, property, options) {
        calls.push({ element, property, options });
        return value => calls.push({ property, value });
    } } };
    vm.runInNewContext(body + '\nthis.scale = quickScaleTo;', context);
    const element = {};
    const setter = context.scale(element, { duration: 0.16 });
    setter(0.978); setter(1);
    assert.deepEqual(calls.map(call => call.property), ['scaleX', 'scaleY', 'scaleX', 'scaleY', 'scaleX', 'scaleY']);
    assert.equal(calls[0].options.overwrite, 'auto');
    assert.equal(calls[1].options.duration, 0.16);
});
