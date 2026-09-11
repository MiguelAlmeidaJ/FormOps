(() => {
    const notice = document.querySelector('[data-formops-cookie-notice]');
    if (!notice) return;

    const storageKey = 'formops-cookie-notice-v1';
    let acknowledged = false;
    try {
        acknowledged = localStorage.getItem(storageKey) === 'acknowledged';
    } catch (_) {}

    if (!acknowledged) notice.hidden = false;

    notice.querySelector('[data-formops-cookie-ack]')?.addEventListener('click', () => {
        try {
            localStorage.setItem(storageKey, 'acknowledged');
        } catch (_) {}
        notice.hidden = true;
    });
})();
