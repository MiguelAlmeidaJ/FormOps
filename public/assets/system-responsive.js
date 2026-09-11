(() => {
    const sidebar = document.querySelector('.admin-sidebar, .system-sidebar');
    const topbar = document.querySelector('.admin-topbar, .system-topbar');
    if (!sidebar || !topbar) return;

    const media = window.matchMedia('(max-width: 991.98px)');
    const isAdmin = sidebar.classList.contains('admin-sidebar');
    const topbarRow = isAdmin
        ? topbar.querySelector('.container-fluid > .d-flex')
        : topbar.querySelector('.container-fluid');

    if (!topbarRow) return;

    document.body.classList.add('formops-mobile-nav-ready');

    if (!sidebar.id) {
        sidebar.id = isAdmin ? 'admin-mobile-navigation' : 'system-mobile-navigation';
    }

    sidebar.setAttribute('aria-label', isAdmin ? 'Navegação da organização' : 'Navegação do sistema');

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'mobile-menu-toggle';
    toggle.setAttribute('aria-controls', sidebar.id);
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Abrir menu');
    toggle.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16M4 12h16M4 17h16"/></svg>';
    topbarRow.insertBefore(toggle, topbarRow.firstChild);

    const backdrop = document.createElement('button');
    backdrop.type = 'button';
    backdrop.className = 'mobile-nav-backdrop';
    backdrop.setAttribute('aria-label', 'Fechar menu');
    sidebar.insertAdjacentElement('afterend', backdrop);

    let lastFocused = null;

    const focusFirstNavigationItem = () => {
        const firstLink = sidebar.querySelector('a[href]');
        if (firstLink) firstLink.focus({ preventScroll: true });
    };

    const openMenu = () => {
        if (!media.matches) return;
        lastFocused = document.activeElement;
        document.body.classList.add('mobile-nav-open');
        toggle.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-label', 'Fechar menu');
        sidebar.setAttribute('aria-hidden', 'false');
        window.setTimeout(focusFirstNavigationItem, 80);
    };

    const closeMenu = (restoreFocus = true) => {
        document.body.classList.remove('mobile-nav-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Abrir menu');
        if (media.matches) sidebar.setAttribute('aria-hidden', 'true');
        else sidebar.removeAttribute('aria-hidden');

        if (restoreFocus && lastFocused instanceof HTMLElement) {
            lastFocused.focus({ preventScroll: true });
        }
        lastFocused = null;
    };

    toggle.addEventListener('click', () => {
        if (document.body.classList.contains('mobile-nav-open')) closeMenu(false);
        else openMenu();
    });

    backdrop.addEventListener('click', () => closeMenu());

    sidebar.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (link && media.matches) closeMenu(false);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && document.body.classList.contains('mobile-nav-open')) {
            event.preventDefault();
            closeMenu();
        }

        if (event.key !== 'Tab' || !media.matches || !document.body.classList.contains('mobile-nav-open')) return;

        const focusable = Array.from(sidebar.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
            .filter((element) => element.offsetParent !== null);

        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    const syncMode = () => {
        if (media.matches) {
            sidebar.setAttribute('aria-hidden', document.body.classList.contains('mobile-nav-open') ? 'false' : 'true');
        } else {
            document.body.classList.remove('mobile-nav-open');
            sidebar.removeAttribute('aria-hidden');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-label', 'Abrir menu');
        }
    };

    if (typeof media.addEventListener === 'function') {
        media.addEventListener('change', syncMode);
    } else if (typeof media.addListener === 'function') {
        media.addListener(syncMode);
    }

    syncMode();
})();
