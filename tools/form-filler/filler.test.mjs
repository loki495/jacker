// Run with `npm test` in tools/form-filler. Headless Chromium against a local fixture server; no real site is contacted.
import assert from 'node:assert/strict';
import http from 'node:http';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after, before, test } from 'node:test';
import { SubmitRefused, guardedClick, isSubmitLike } from './guard.mjs';
import { launch } from './launch.mjs';

const requests = [];
let server, base, profile, session;

before(async () => {
    server = http.createServer((req, res) => {
        requests.push(`${req.method} ${req.url}`);
        res.setHeader('Content-Type', 'text/html');
        res.end(req.url === '/other' ? '<p>elsewhere</p>' : `<form id="f" method="post" action="/submit">
            <input id="name" name="name"><button id="go">Submit application</button></form>
            <button id="plain" type="button">Add another</button>
            <a id="away" href="http://other.invalid/x">leave</a>`);
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    base = `http://127.0.0.1:${server.address().port}`;
    profile = mkdtempSync(join(tmpdir(), 'form-filler-test-'));
    session = await launch({ url: `${base}/form`, profileDir: profile, headless: true, log: (e) => requests.push(`LOG ${e.event}`) });
});
after(async () => {
    await session?.context.close();
    server.closeAllConnections?.();
    server.close();
    rmSync(profile, { recursive: true, force: true });
});

const posted = () => requests.filter((r) => r.startsWith('POST'));

test('classifies submit-like controls', () => {
    assert.ok(isSubmitLike({ tag: 'input', type: 'submit' }));
    assert.ok(isSubmitLike({ tag: 'button', type: '', inForm: true, text: 'Go' }));
    assert.ok(isSubmitLike({ tag: 'button', type: 'button', text: 'Send application' }));
    assert.ok(!isSubmitLike({ tag: 'button', type: 'button', text: 'Add another' }));
});

test('fills a field and the click wrapper refuses the only Submit button', async () => {
    await session.page.fill('#name', 'Andres');
    assert.equal(await session.page.inputValue('#name'), 'Andres');
    await assert.rejects(guardedClick(session.page.locator('#go')), SubmitRefused);
    assert.deepEqual(posted(), []);
});

test('the wrapper still clicks harmless buttons', async () => {
    await guardedClick(session.page.locator('#plain'));
});

test('Enter, form.submit() and requestSubmit() send nothing', async () => {
    await session.page.focus('#name');
    await session.page.keyboard.press('Enter');
    await session.page.evaluate(() => { document.getElementById('f').submit(); document.getElementById('f').requestSubmit(); });
    await session.page.waitForTimeout(300);
    assert.deepEqual(posted(), []);
    assert.equal(new URL(session.page.url()).pathname, '/form');
});

test('a direct click bypassing the wrapper is still cancelled by the page script', async () => {
    await session.page.click('#go');
    await session.page.waitForTimeout(300);
    assert.deepEqual(posted(), []);
});

test('navigation away from the form origin is blocked and logged', async () => {
    await session.page.click('#away', { noWaitAfter: true }).catch(() => {});
    await session.page.waitForTimeout(300);
    assert.equal(new URL(session.page.url()).origin, base);
    assert.ok(requests.includes('LOG navigation-blocked'));
});

test('closing the window resolves the closed promise', async () => {
    const own = await launch({ url: `${base}/form`, profileDir: mkdtempSync(join(tmpdir(), 'form-filler-test-')), headless: true, log: () => {} });
    let done = false;
    own.closed.then(() => { done = true; });
    await new Promise((r) => setTimeout(r, 300));
    assert.equal(done, false, 'window must stay open until closed');
    await own.context.close();
    await own.closed;
    assert.equal(done, true);
});
