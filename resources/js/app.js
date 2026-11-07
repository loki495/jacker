// iOS standalone has no native pull-to-refresh: reload when the page is pulled down past THRESHOLD (after damping).
if (navigator.standalone) {
    const THRESHOLD = 90;
    let startY = null;
    let pull = 0;
    const hint = document.createElement('div');
    hint.style.cssText = 'position:fixed;top:0;left:50%;z-index:9999;display:flex;gap:8px;align-items:center;padding:8px 16px;border-radius:999px;background:#222;color:#fff;font:14px system-ui;opacity:0;pointer-events:none;transform:translate(-50%,-60px)';
    const arrow = document.createElement('span');
    const label = document.createElement('span');
    arrow.textContent = '↓';
    arrow.style.transition = 'transform .15s';
    hint.append(arrow, label);
    document.addEventListener('DOMContentLoaded', () => document.body.append(hint));

    const show = (y, ready) => {
        hint.style.transition = y === null ? 'transform .2s, opacity .2s' : 'none';
        hint.style.opacity = y === null ? 0 : Math.min(1, y / 40);
        hint.style.transform = `translate(-50%, ${y === null ? -60 : y - 50 + (parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--sat')) || 0)}px)`;
        arrow.style.transform = ready ? 'rotate(180deg)' : '';
        label.textContent = ready ? 'Release to refresh' : 'Pull down to refresh';
    };

    addEventListener('touchstart', (e) => {
        startY = scrollY <= 0 && !e.target.closest('[data-flux-modal], dialog[open], .table-scroll') ? e.touches[0].clientY : null;
        pull = 0;
    }, { passive: true });
    addEventListener('touchmove', (e) => {
        if (startY === null) return;
        pull = Math.max(0, (e.touches[0].clientY - startY) * 0.5);
        if (pull > 0) show(Math.min(pull, THRESHOLD + 30) + 20, pull >= THRESHOLD);
    }, { passive: true });
    addEventListener('touchend', () => {
        const go = startY !== null && pull >= THRESHOLD;
        startY = null;
        if (go) { label.textContent = 'Refreshing…'; location.reload(); } else { show(null, false); }
    });
    addEventListener('touchcancel', () => { startY = null; show(null, false); });
}

if ('serviceWorker' in navigator) addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
