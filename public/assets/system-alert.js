(function () {
    'use strict';

    const confirmedForms = new WeakSet();
    let activeDialog = null;

    const icons = {
        info: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 11v6M12 7.5v.01"></path></svg>',
        success: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="m8 12 2.7 2.7L16.5 9"></path></svg>',
        warning: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.3 4.3 3.2 17a2 2 0 0 0 1.7 3h14.2a2 2 0 0 0 1.7-3L13.7 4.3a2 2 0 0 0-3.4 0Z"></path><path d="M12 9v4M12 16.5v.01"></path></svg>',
        danger: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="m9 9 6 6m0-6-6 6"></path></svg>'
    };

    function closeDialog(result) {
        if (!activeDialog) return;

        const { overlay, resolve, previousFocus, onKeydown } = activeDialog;
        activeDialog = null;
        document.removeEventListener('keydown', onKeydown);
        overlay.classList.remove('is-visible');
        document.body.classList.remove('formops-alert-open');

        window.setTimeout(function () {
            overlay.remove();
            if (previousFocus && document.contains(previousFocus)) previousFocus.focus();
            resolve(result);
        }, 160);
    }

    function show(options) {
        const settings = Object.assign({
            title: 'Atenção',
            message: '',
            type: 'info',
            confirmText: 'OK',
            cancelText: 'Cancelar',
            cancelable: false
        }, options || {});

        if (!icons[settings.type]) settings.type = 'info';
        if (activeDialog) closeDialog(false);

        return new Promise(function (resolve) {
            const overlay = document.createElement('div');
            overlay.className = 'formops-alert-overlay';
            overlay.innerHTML =
                '<section class="formops-alert formops-alert--' + settings.type + '" role="alertdialog" aria-modal="true" aria-labelledby="formops-alert-title" aria-describedby="formops-alert-message">' +
                    '<div class="formops-alert-icon">' + icons[settings.type] + '</div>' +
                    '<div class="formops-alert-content">' +
                        '<h2 id="formops-alert-title"></h2>' +
                        '<p id="formops-alert-message"></p>' +
                    '</div>' +
                    '<div class="formops-alert-actions"></div>' +
                '</section>';

            const title = overlay.querySelector('#formops-alert-title');
            const message = overlay.querySelector('#formops-alert-message');
            const actions = overlay.querySelector('.formops-alert-actions');
            title.textContent = settings.title;
            message.textContent = settings.message;

            if (settings.cancelable) {
                const cancelButton = document.createElement('button');
                cancelButton.type = 'button';
                cancelButton.className = 'formops-alert-button formops-alert-button--secondary';
                cancelButton.textContent = settings.cancelText;
                cancelButton.addEventListener('click', function () { closeDialog(false); });
                actions.appendChild(cancelButton);
            }

            const confirmButton = document.createElement('button');
            confirmButton.type = 'button';
            confirmButton.className = 'formops-alert-button formops-alert-button--primary';
            confirmButton.textContent = settings.confirmText;
            confirmButton.addEventListener('click', function () { closeDialog(true); });
            actions.appendChild(confirmButton);

            const onKeydown = function (event) {
                if (event.key === 'Escape' && settings.cancelable) closeDialog(false);
                if (event.key !== 'Tab') return;

                const buttons = Array.from(overlay.querySelectorAll('button'));
                const first = buttons[0];
                const last = buttons[buttons.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            };

            activeDialog = {
                overlay: overlay,
                resolve: resolve,
                previousFocus: document.activeElement,
                onKeydown: onKeydown
            };

            document.body.appendChild(overlay);
            document.body.classList.add('formops-alert-open');
            document.addEventListener('keydown', onKeydown);
            overlay.addEventListener('click', function (event) {
                if (event.target === overlay && settings.cancelable) closeDialog(false);
            });

            window.requestAnimationFrame(function () {
                overlay.classList.add('is-visible');
                confirmButton.focus();
            });
        });
    }

    function inferType(message) {
        return /excluir|apagar|remover|resetar|não poderá ser desfeita/i.test(message) ? 'danger' : 'warning';
    }

    window.FormOpsAlert = {
        show: show,
        alert: function (message, options) {
            return show(Object.assign({}, options, { message: message, cancelable: false }));
        },
        confirm: function (message, options) {
            return show(Object.assign({
                title: 'Confirmar ação',
                type: inferType(message),
                confirmText: 'Confirmar',
                cancelable: true
            }, options, { message: message, cancelable: true }));
        }
    };

    document.addEventListener('submit', async function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) return;

        if (confirmedForms.has(form)) {
            confirmedForms.delete(form);
            return;
        }

        event.preventDefault();
        const accepted = await window.FormOpsAlert.confirm(form.dataset.confirm, {
            title: form.dataset.confirmTitle || 'Confirmar ação',
            confirmText: form.dataset.confirmButton || 'Confirmar',
            type: form.dataset.confirmVariant || inferType(form.dataset.confirm)
        });

        if (!accepted) return;
        confirmedForms.add(form);
        if (typeof form.requestSubmit === 'function') form.requestSubmit(event.submitter || undefined);
        else HTMLFormElement.prototype.submit.call(form);
    }, true);
}());
