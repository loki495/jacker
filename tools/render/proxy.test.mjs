// Run with `node --test` in tools/render. Stands in for the vetting callback so no real network or Chromium is needed.
import assert from 'node:assert/strict';
import http from 'node:http';
import net from 'node:net';
import { after, before, test } from 'node:test';
import { startProxy } from './proxy.mjs';

let origin, secret, proxy;
const hits = { secret: 0 };

before(async () => {
    origin = http.createServer((req, res) => {
        if (req.url === '/redirect') return res.writeHead(302, { Location: `http://private.test:${secret.address().port}/secret` }).end();
        res.end('public page');
    });
    secret = http.createServer((req, res) => {
        hits.secret++;
        res.end('internal');
    });
    await Promise.all([origin, secret].map((s) => new Promise((resolve) => s.listen(0, '127.0.0.1', resolve))));
    // public.test is vetted to the local origin; everything else, including private.test, is refused.
    proxy = await startProxy(async (host) => {
        if (host === 'public.test') return ['127.0.0.1'];
        throw new Error('not public');
    });
});
after(() => [origin, secret, proxy].forEach((s) => s.close()));

function viaProxy(target, path = '/') {
    return new Promise((resolve, reject) => {
        http.get({ host: '127.0.0.1', port: proxy.address().port, path: `http://${target}${path}`, headers: { Host: target } }, (res) => {
            let body = '';
            res.on('data', (c) => (body += c)).on('end', () => resolve({ status: res.statusCode, body, headers: res.headers }));
        }).on('error', reject);
    });
}

test('connects to the vetted address, not to whatever the name resolves to', async () => {
    const reply = await viaProxy(`public.test:${origin.address().port}`);
    assert.equal(reply.body, 'public page');
});

test('refuses names the vetting callback rejects and never reaches the internal server', async () => {
    const reply = await viaProxy(`private.test:${secret.address().port}`, '/secret');
    assert.equal(reply.status, 403);
    assert.equal(hits.secret, 0);
});

test('a redirect to a private name is refused when the browser follows it', async () => {
    const first = await viaProxy(`public.test:${origin.address().port}`, '/redirect');
    assert.equal(first.status, 302);
    const next = new URL(first.headers.location);
    const followed = await viaProxy(next.host, next.pathname);
    assert.equal(followed.status, 403);
    assert.equal(hits.secret, 0);
});

test('CONNECT tunnels to vetted hosts only and rejects malformed targets', async () => {
    const connect = (target) => new Promise((resolve) => {
        const socket = net.connect(proxy.address().port, '127.0.0.1', () => socket.write(`CONNECT ${target} HTTP/1.1\r\nHost: ${target}\r\n\r\n`));
        socket.once('data', (data) => {
            socket.destroy();
            resolve(data.toString().split(' ')[1]);
        });
    });
    assert.equal(await connect(`public.test:${origin.address().port}`), '200');
    assert.equal(await connect(`private.test:${secret.address().port}`), '403');
    assert.equal(await connect('nonsense'), '403');
    assert.equal(hits.secret, 0);
});
