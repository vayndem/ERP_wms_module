let sessionExpiredShown = false;

export function handleSessionExpired(response) {
    if (!response || (response.status !== 401 && response.status !== 419)) return false;
    if (sessionExpiredShown) return true;
    sessionExpiredShown = true;

    const loginUrl = document.querySelector('meta[name="login-url"]')?.content || '/';
    const redirect = () => { window.location.assign(loginUrl); };
    const alert = window.AppAlert?.warning(
        'Sesi Anda telah berakhir. Silakan masuk kembali.',
        'Sesi berakhir'
    );

    if (alert && typeof alert.then === 'function') {
        alert.then(redirect, redirect);
    } else {
        redirect();
    }
    return true;
}

export async function openAjaxModal(url) {
    const container = document.getElementById('modal-container');
    if (!container) return;

    try {
        const response = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
        });
        if (handleSessionExpired(response)) return;
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        const html = await response.text();
        container.innerHTML = html;

        container.querySelectorAll('script').forEach((oldScript) => {
            const newScript = document.createElement('script');
            for (const attr of oldScript.attributes) {
                newScript.setAttribute(attr.name, attr.value);
            }
            newScript.textContent = oldScript.textContent;
            oldScript.replaceWith(newScript);
        });

        const dialog = container.querySelector('dialog');
        if (!dialog) return;

        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
        dialog.addEventListener('close', () => {
            setTimeout(() => { container.innerHTML = ''; }, 200);
        });
        dialog.showModal();
    } catch (error) {
        window.AppAlert?.error('Gagal memuat form. Silakan coba kembali.');
    }
}

export function closeAjaxModal(el) {
    const dialog = el.closest('dialog');
    dialog?.close();
}

export async function submitAjaxForm(event, options = {}) {
    const form = event.target.closest ? (event.target.closest('form') || event.target) : event.target;
    const submitBtn = form.querySelector('[type="submit"]');
    submitBtn?.setAttribute('disabled', 'disabled');

    try {
        const method = form.querySelector('input[name="_method"]')?.value.toUpperCase() || 'POST';
        const response = await fetch(form.action, {
            method,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: new URLSearchParams(new FormData(form)),
        });
        if (handleSessionExpired(response)) return false;
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            window.AppAlert?.ajaxError(data);
            return false;
        }

        if (data.message) window.AppAlert?.auto(data);
        form.dispatchEvent(new Event('wms:saved'));
        window.dispatchEvent(new CustomEvent('wms:table-refresh'));
        form.closest('dialog')?.close();
        options.onSuccess?.(data);
        return true;
    } catch (error) {
        window.AppAlert?.error('Gagal memproses permintaan.');
        return false;
    } finally {
        submitBtn?.removeAttribute('disabled');
    }
}

export async function confirmAjaxDelete(event, message = 'Yakin ingin menghapus data ini?') {
    event.preventDefault();
    const result = await window.AppAlert.confirm(message);
    if (!result.isConfirmed) return;
    await submitAjaxForm(event);
}

window.handleSessionExpired = handleSessionExpired;
window.openAjaxModal = openAjaxModal;
window.closeAjaxModal = closeAjaxModal;
window.submitAjaxForm = submitAjaxForm;
window.confirmAjaxDelete = confirmAjaxDelete;
