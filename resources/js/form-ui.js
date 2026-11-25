let fieldSequence = 0;

function labelFor(field) {
    if (field.labels?.length) return field.labels[0];

    const formControl = field.closest('.form-control');
    const label = formControl?.querySelector('label') || field.parentElement?.querySelector(':scope > label');
    if (!label) return null;

    if (!field.id) field.id = `wms-field-${++fieldSequence}`;
    label.htmlFor = field.id;
    return label;
}

function markRequired(field, label) {
    if (!field.required || !label || label.querySelector('[data-required-accessible]')) return;

    if (!label.textContent.includes('*')) {
        const marker = document.createElement('span');
        marker.dataset.requiredMarker = '';
        marker.className = 'ml-1 text-error';
        marker.setAttribute('aria-hidden', 'true');
        marker.textContent = '*';
        label.append(marker);
    }

    const accessible = document.createElement('span');
    accessible.dataset.requiredAccessible = '';
    accessible.className = 'sr-only';
    accessible.textContent = ' (wajib)';
    label.append(accessible);
}

function enhanceField(field) {
    if (field.type === 'hidden') return;

    const label = labelFor(field);
    if (!label && !field.getAttribute('aria-label') && field.placeholder) {
        field.setAttribute('aria-label', field.placeholder);
    }
    markRequired(field, label);
}

function fieldNameFromError(name) {
    const parts = name.split('.');
    return parts.shift() + parts.map((part) => `[${part}]`).join('');
}

function showValidationErrors() {
    const source = document.getElementById('wms-validation-errors');
    if (!source) return;

    const errors = [...source.querySelectorAll('[data-field-error]')];
    if (errors.length === 0) return;

    const firstMessage = errors[0].textContent.trim();
    const existingAlert = [...document.querySelectorAll('.alert, [role="alert"]')]
        .some((element) => element.textContent.includes(firstMessage));

    if (!existingAlert) {
        const summary = document.createElement('div');
        summary.className = 'alert alert-error mb-4';
        summary.setAttribute('role', 'alert');
        summary.innerHTML = '<i class="fa-solid fa-circle-xmark" aria-hidden="true"></i><div><p class="font-semibold">Periksa kembali data berikut:</p><ul class="mt-1 list-inside list-disc"></ul></div>';
        const list = summary.querySelector('ul');
        errors.forEach((error) => {
            const item = document.createElement('li');
            item.textContent = error.textContent;
            list.append(item);
        });
        document.querySelector('.content-page')?.prepend(summary);
    }

    errors.forEach((error, index) => {
        const name = fieldNameFromError(error.dataset.fieldError);
        const field = [...document.querySelectorAll('[name]')].find((candidate) => candidate.name === name);
        if (!field) return;

        const message = error.textContent.trim();
        const wrapper = field.closest('.form-control') || field.parentElement;
        if ([...wrapper.querySelectorAll('.text-error')].some((element) => element.textContent.trim() === message)) return;

        const id = `wms-field-error-${index + 1}`;
        const text = document.createElement('p');
        text.id = id;
        text.className = 'mt-1 text-sm text-error';
        text.textContent = message;
        wrapper.append(text);
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), id].filter(Boolean).join(' '));
    });
}

function submittingButton(form, submitter) {
    return submitter || form.querySelector('[type="submit"], button:not([type])');
}

function begin(form, submitter = null) {
    if (form.dataset.wmsSubmitting === 'true') return false;

    form.dataset.wmsSubmitting = 'true';
    form.setAttribute('aria-busy', 'true');
    const activeButton = submittingButton(form, submitter);

    form.querySelectorAll('[type="submit"], button:not([type])').forEach((button) => {
        button.disabled = true;
    });

    if (activeButton) {
        activeButton.dataset.wmsOriginalHtml = activeButton.innerHTML;
        activeButton.innerHTML = '<span class="loading loading-spinner loading-sm" aria-hidden="true"></span><span>Memproses...</span>';
    }
    return true;
}

function end(form) {
    form.dataset.wmsSubmitting = 'false';
    form.removeAttribute('aria-busy');
    form.querySelectorAll('[type="submit"], button:not([type])').forEach((button) => {
        button.disabled = false;
        if (button.dataset.wmsOriginalHtml !== undefined) {
            button.innerHTML = button.dataset.wmsOriginalHtml;
            delete button.dataset.wmsOriginalHtml;
        }
    });
}

function enhance(root = document) {
    root.querySelectorAll?.('input, select, textarea').forEach(enhanceField);
}

const nativeSubmit = HTMLFormElement.prototype.submit;
HTMLFormElement.prototype.submit = function () {
    if (begin(this)) nativeSubmit.call(this);
};

document.addEventListener('submit', (event) => {
    if (event.defaultPrevented) return;
    if (!begin(event.target, event.submitter)) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
});

document.addEventListener('DOMContentLoaded', () => {
    enhance();
    showValidationErrors();

    new MutationObserver((mutations) => {
        mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
            if (node.nodeType !== Node.ELEMENT_NODE) return;
            if (node.matches('input, select, textarea')) enhanceField(node);
            enhance(node);
        }));
    }).observe(document.body, { childList: true, subtree: true });
}, { once: true });

window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-wms-submitting="true"]').forEach(end);
});

window.wmsFormSubmit = { begin, end };
