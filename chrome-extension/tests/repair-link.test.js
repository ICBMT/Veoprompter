import test from 'node:test';
import assert from 'node:assert/strict';
import { savedRunReviewLink } from '../repair-link.js';

test('saved-run repair links target only a connected app origin and numeric history id', () => {
    assert.equal(
        savedRunReviewLink('https://app.example', 27, ['https://app.example']),
        'https://app.example/history/27',
    );
    assert.equal(
        savedRunReviewLink('https://app.example', '27', ['https://app.example']),
        'https://app.example/history/27',
    );
});

test('saved-run repair links reject invalid ids and unapproved or non-web origins', () => {
    const connected = ['https://app.example'];
    for (const id of [null, '', 0, -1, 1.2, '1e2', 'abc', Number.MAX_SAFE_INTEGER + 1]) {
        assert.equal(savedRunReviewLink('https://app.example', id, connected), null);
    }
    assert.equal(savedRunReviewLink('https://other.example', 27, connected), null);
    assert.equal(savedRunReviewLink('chrome-extension://abc', 27, connected), null);
    assert.equal(savedRunReviewLink('https://app.example/history', 27, connected), null);
});
