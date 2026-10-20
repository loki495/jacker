// Renders one page in headless Chromium and prints its visible text on stdout.
// Usage: node render.mjs <url>. Every request the browser makes (redirects and scripts included) is
// vetted by the app's UrlGuard through `render:allow`; images, media, fonts and stylesheets are not loaded.
import { execFile } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { promisify } from 'node:util';
import { chromium } from 'playwright-core';

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

const verdicts = new Map();
function allowed(target) {
    let host;
    try {
        const parsed = new URL(target);
        if (!['http:', 'https:'].includes(parsed.protocol)) return Promise.resolve(false);
        host = parsed.origin;
    } catch {
        return Promise.resolve(false);
    }
    if (!verdicts.has(host)) {
        verdicts.set(host, run('docker', ['exec', '--user', 'www-data', container, 'php', 'artisan', 'render:allow', host]).then(() => true, () => false));
    }
    return verdicts.get(host);
}

const browser = await chromium.launch({ executablePath: chromePath(), headless: true, args: ['--no-sandbox'] });
try {
    const context = await browser.newContext({ acceptDownloads: false, serviceWorkers: 'block' });
    await context.route('**/*', async (route) => {
        const request = route.request();
        if (['image', 'media', 'font', 'stylesheet'].includes(request.resourceType())) return route.abort();
        return (await allowed(request.url())) ? route.continue() : route.abort('blockedbyclient');
    });
    const page = await context.newPage();
    if (!(await allowed(url))) throw new Error('The address is not public.');
    const response = await page.goto(url, { waitUntil: 'networkidle', timeout });
    if (response && response.status() >= 400) throw new Error(`The site answered HTTP ${response.status()}.`);
    process.stdout.write(await page.evaluate(() => document.body.innerText));
} finally {
    await browser.close();
}
