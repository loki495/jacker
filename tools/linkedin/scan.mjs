// Reads one LinkedIn job page in a signed-in Chrome profile and prints what a listing needs as JSON.
// Usage: node scan.mjs <url>        scan one posting, print {title, company, location, description, apply_url}
//        node scan.mjs --login      open LinkedIn so you can sign in once; the profile keeps the session
//
// LinkedIn refuses plain fetches and the agent chain, so this drives a real browser with a profile you
// logged into once. It never posts, applies or clicks anything: it opens the posting and reads the page.
import { existsSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { chromium } from 'playwright-core';

const profileDir = process.env.LINKEDIN_PROFILE ?? join(homedir(), '.local', 'share', 'jacker', 'linkedin-profile');
const chromePath = process.env.LINKEDIN_CHROME ?? ['/usr/bin/google-chrome-stable', '/opt/google/chrome/chrome'].find((candidate) => existsSync(candidate));
const headless = process.env.LINKEDIN_HEADLESS === '1';
const navTimeout = Number(process.env.LINKEDIN_NAV_TIMEOUT_MS ?? 45000);
const loginWaitMs = Number(process.env.LINKEDIN_LOGIN_WAIT_MS ?? 600000);

function fail(message, code = 3) {
    console.error(message);
    process.exit(code);
}

async function launch() {
    try {
        return await chromium.launchPersistentContext(profileDir, {
            headless,
            executablePath: chromePath,
            viewport: { width: 1280, height: 900 },
            args: ['--no-first-run', '--no-default-browser-check'],
        });
    } catch (error) {
        fail(`could not start Chrome: ${error.message}`);
    }
}

const arg = process.argv[2];
if (!arg) fail('usage: scan.mjs <url> | --login', 2);

const context = await launch();

// process.exit skips `finally`, which killed Chrome uncleanly and left a stale profile lock; close first.
async function bail(message, code = 3) {
    await context.close().catch(() => {});
    fail(message, code);
}

async function finish(code) {
    await context.close().catch(() => {});
    process.exit(code);
}
const page = context.pages()[0] ?? (await context.newPage());

try {
    if (arg === '--login') {
        await page.goto('https://www.linkedin.com/login', { waitUntil: 'domcontentloaded', timeout: navTimeout });
        console.error('Sign in in the window that just opened. It closes itself once you are signed in, or after a timeout.');
        const deadline = Date.now() + loginWaitMs;
        while (Date.now() < deadline) {
            if (!/\/login|authwall/.test(page.url())) {
                console.error('Signed in; the profile now keeps the session.');
                await finish(0);
            }
            await new Promise((resolve) => setTimeout(resolve, 2000));
        }
        await bail('the sign-in did not finish in time', 4);
    }

    await page.goto(arg, { waitUntil: 'domcontentloaded', timeout: navTimeout });
    if (/\/login|authwall|checkpoint/.test(page.url())) {
        await bail('LinkedIn asked for a sign-in: run bin/linkedin-scan --login once, then queue the link again', 5);
    }

    await page.waitForSelector('h1', { timeout: 15000 }).catch(() => {});

    const data = await page.evaluate(() => {
        const text = (node) => (node?.innerText ?? '').trim().replace(/\s+\n/g, '\n');
        // First match with visible text: a bare icon or nav link can match a selector and be empty.
        const first = (selectors) => {
            for (const selector of selectors) {
                const node = Array.from(document.querySelectorAll(selector)).find((n) => (n.innerText ?? '').trim());
                if (node) return node;
            }
            return null;
        };

        const title = text(first(['h1.t-24', '.job-details-jobs-unified-top-card__job-title', 'h1.top-card-layout__title', 'h1']));
        const company = text(first([
            '.topcard__org-name-link',
            '.job-details-jobs-unified-top-card__company-name a',
            '.job-details-jobs-unified-top-card__company-name',
            'a[href*="/company/"]',
        ]));
        const location = text(first([
            '.topcard__flavor--bullet',
            '.job-details-jobs-unified-top-card__primary-description-container .tvm__text',
            '.job-details-jobs-unified-top-card__bullet',
            '.job-details-jobs-unified-top-card__workplace-type',
        ]));
        const description = text(first([
            '.jobs-description-content__text',
            '.show-more-less-html__markup',
            '.jobs-box__html-content',
            '#job-details',
        ]));

        const applyAnchor = Array.from(document.querySelectorAll('a')).find((a) => {
            const label = (a.innerText || a.getAttribute('aria-label') || '').trim();
            return /^apply$/i.test(label) && /^https?:/i.test(a.href) && !/linkedin\.com/i.test(a.href);
        });

        const easyApply = Array.from(document.querySelectorAll('button, a')).some((n) => /easy apply/i.test(n.innerText || n.getAttribute('aria-label') || ''));

        return { title, company, location, description, apply_url: applyAnchor?.href ?? null, easy_apply: easyApply };
    });

    if (!data.title || !data.company) await bail('the page did not show a job title and company');

    console.log(JSON.stringify({
        title: data.title,
        company: data.company,
        location: data.location || null,
        description: data.description || null,
        apply_url: data.apply_url || null,
        easy_apply: data.easy_apply,
    }));

    await finish(0);
} finally {
    await context.close().catch(() => {});
}
