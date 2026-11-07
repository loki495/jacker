// Opens `url` in a persistent, visible Chromium window with the guards installed and stays alive until the owner closes the window.
// Usage: node launch.mjs <url> [profile-dir]. Needs a display (DISPLAY or WAYLAND_DISPLAY); the agent worker passes it through.
import { chromium } from 'playwright-core';
import { existsSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { guardContext } from './guard.mjs';

// Same lookup as tools/linkedin: the installed Chrome, so no Playwright browser download is needed.
const chromePath = process.env.FORM_FILLER_CHROME ?? ['/usr/bin/google-chrome-stable', '/opt/google/chrome/chrome'].find((candidate) => existsSync(candidate));

export async function launch({ url, profileDir, headless = false, log = (e) => console.log(JSON.stringify(e)) }) {
    const context = await chromium.launchPersistentContext(profileDir, { headless, executablePath: chromePath, viewport: null, args: ['--start-maximized'] });
    await guardContext(context, url, log);
    const page = context.pages()[0] ?? (await context.newPage());
    await page.goto(url);
    const closed = new Promise((resolve) => context.on('close', resolve));

    return { context, page, closed };
}

if (import.meta.url === `file://${process.argv[1]}`) {
    const [url, profile = join(homedir(), '.cache', 'jacker-form-filler')] = process.argv.slice(2);
    if (!/^https?:\/\//i.test(url ?? '')) {
        console.error('usage: node launch.mjs <http(s) url> [profile-dir]');
        process.exit(2);
    }
    // The window must outlive the worker that started it: ignore hangups, and leave only the owner closing it.
    process.on('SIGHUP', () => {});
    const { closed } = await launch({ url, profileDir: profile });
    await closed;
    process.exit(0);
}
