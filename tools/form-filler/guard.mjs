// The filler may type into fields but must never submit or leave the form. Three layers: a click wrapper that refuses submit-like controls,
// a page script that cancels every submit path (form.submit(), requestSubmit(), Enter, submit events), and a route guard that aborts
// navigation away from the form's origin and any non-GET document request. The owner presses Submit; nothing here ever does.

// ponytail: text match is English-only and errs toward refusing; widen the list or add an explicit allowNavigation click when phase 2 needs "Apply Now".
const SUBMIT_TEXT = /\b(submit|send|apply|finish|confirm|complete|place order|post)\b/i;

export function isSubmitLike({ tag = '', type = '', text = '', inForm = false }) {
    const t = type.toLowerCase();
    if (t === 'submit' || t === 'image') return true;
    if (tag === 'button' && t === '' && inForm) return true; // a typeless button inside a form submits it
    return SUBMIT_TEXT.test(text);
}

export class SubmitRefused extends Error {}

export async function describe(locator) {
    return locator.evaluate((el) => ({
        tag: el.tagName.toLowerCase(),
        type: el.getAttribute('type') ?? '',
        inForm: el.closest('form') !== null,
        text: [el.textContent, el.getAttribute('value'), el.getAttribute('aria-label'), el.getAttribute('title')].filter(Boolean).join(' ').trim(),
    }));
}

/** The only way the filler clicks: refuses anything that could submit. */
export async function guardedClick(locator, log = () => {}) {
    const d = await describe(locator);
    if (isSubmitLike(d)) {
        log({ event: 'submit-refused', control: d });
        throw new SubmitRefused(`refused to click a submit-like control: ${d.text || d.type || d.tag}`);
    }
    await locator.click();
}

const BLOCK_SUBMIT = `(() => {
    const stop = (e) => { e.preventDefault(); e.stopImmediatePropagation(); };
    window.addEventListener('submit', stop, true);
    HTMLFormElement.prototype.submit = function () {};
    HTMLFormElement.prototype.requestSubmit = function () {};
})();`;

export async function guardContext(context, formUrl, log = () => {}) {
    const origin = new URL(formUrl).origin;
    await context.addInitScript(BLOCK_SUBMIT);
    await context.route('**/*', (route) => {
        const req = route.request();
        if (req.resourceType() === 'document' && req.isNavigationRequest()) {
            const away = new URL(req.url()).origin !== origin;
            if (away || req.method() !== 'GET') {
                log({ event: away ? 'navigation-blocked' : 'post-blocked', url: req.url(), method: req.method() });
                return route.fulfill({ status: 204 }); // 204 leaves the form on screen; an abort would replace it with an error page
            }
        }
        return route.continue();
    });
}
