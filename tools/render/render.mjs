// Renders one page in headless Chromium and prints its visible text on stdout.
// Usage: node render.mjs <url>. All browser traffic (redirects, scripts, WebSockets) goes through proxy.mjs, which
// connects only to addresses the app's UrlGuard approved through `render:allow`; images, media, fonts and stylesheets are not loaded.
import { execFile } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';
import { mkdtempSync, rmSync } from 'node:fs';
import { homedir, tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { chromium } from 'playwright-core';
import { startProxy } from './proxy.mjs';

const run = promisify(execFile);
const container = process.env.JACKER_CONTAINER ?? 'jacker-app';
const timeout = Number(process.env.RENDER_TIMEOUT_MS ?? 30000);
const url = process.argv[2];
if (!url) {
    console.error('usage: render.mjs <url>');
    process.exit(2);
}

function chromePath() {
    if (process.env.RENDER_CHROME) return process.env.RENDER_CHROME;
    const base = join(homedir(), '.cache', 'ms-playwright');
    const dirs = existsSync(base) ? readdirSync(base).filter((d) => /^chromium-\d+$/.test(d)).sort((a, b) => Number(b.slice(9)) - Number(a.slice(9))) : [];
    for (const dir of dirs) {
        const candidate = join(base, dir, 'chrome-linux64', 'chrome');
        if (existsSync(candidate)) return candidate;
    }
    throw new Error('No Chromium found; set RENDER_CHROME');
}

const vetted = new Map();
// Resolves through the app's UrlGuard (render:allow prints the public addresses, or exits 1); the proxy connects to those.
function vet(host) {
    if (!vetted.has(host)) {
        const target = `https://${host.includes(':') ? `[${host}]` : host}`;
        vetted.set(host, run('docker', ['exec', '--user', 'www-data', container, 'php', 'artisan', 'render:allow', target]).then(({ stdout }) => {
            const addresses = stdout.split('\n').map((line) => line.trim()).filter(Boolean);
            if (addresses.length === 0) throw new Error('not public');
            return addresses;
        }));
    }
    return vetted.get(host);
}

// By default Chromium runs in a network namespace whose only way out is a relay to the proxy's unix socket
// (isolated-chrome.sh); RENDER_ISOLATE=0 falls back to a plain loopback proxy where unprivileged namespaces are unavailable.
const isolate = process.env.RENDER_ISOLATE !== '0';
const relayPort = 3128;
const socketDir = isolate ? mkdtempSync(join(tmpdir(), 'jacker-render-')) : null;
const socketPath = socketDir && join(socketDir, 'proxy.sock');
const proxy = await startProxy(vet, isolate ? { path: socketPath } : undefined);
const proxyServer = isolate ? `http://127.0.0.1:${relayPort}` : `http://127.0.0.1:${proxy.address().port}`;
// ponytail: --no-sandbox only when RENDER_NO_SANDBOX=1 (needed where unprivileged user namespaces are off)
const browser = await chromium.launch({
    executablePath: isolate ? join(dirname(fileURLToPath(import.meta.url)), 'isolated-chrome.sh') : chromePath(),
    headless: true,
    env: { ...process.env, RENDER_PROXY_SOCK: socketPath ?? '', RENDER_RELAY_PORT: String(relayPort), RENDER_CHROME: chromePath() },
    args: [
        `--proxy-server=${proxyServer}`,
        '--proxy-bypass-list=<-loopback>',
        // WebRTC would otherwise send UDP straight out, around the proxy
        '--force-webrtc-ip-handling-policy=disable_non_proxied_udp',
        '--webrtc-ip-handling-policy=disable_non_proxied_udp',
        ...(process.env.RENDER_NO_SANDBOX === '1' ? ['--no-sandbox'] : []),
    ],
});
try {
    const context = await browser.newContext({ acceptDownloads: false, serviceWorkers: 'block' });
    await context.addInitScript(() => {
        for (const name of ['RTCPeerConnection', 'webkitRTCPeerConnection', 'RTCDataChannel']) delete window[name];
    });
    await context.route('**/*', (route) => (['image', 'media', 'font', 'stylesheet'].includes(route.request().resourceType()) ? route.abort() : route.continue()));
    const page = await context.newPage();
    const target = new URL(url);
    if (!['http:', 'https:'].includes(target.protocol)) throw new Error('The address is not public.');
    await vet(target.hostname.replace(/^\[|\]$/g, '')).catch(() => {
        throw new Error('The address is not public.');
    });
    const response = await page.goto(url, { waitUntil: 'networkidle', timeout });
    if (response && response.status() >= 400) throw new Error(`The site answered HTTP ${response.status()}.`);
    process.stdout.write(await page.evaluate(() => document.body.innerText));
} finally {
    await browser.close();
    proxy.close();
    if (socketDir) rmSync(socketDir, { recursive: true, force: true });
}
