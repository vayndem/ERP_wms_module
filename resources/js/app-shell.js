function ready(callback) {
    document.readyState === 'loading'
        ? document.addEventListener('DOMContentLoaded', callback, { once: true })
        : callback();
}

ready(() => {
    const body = document.body;
    const sidebar = document.querySelector('.mm-sidebar');
    const toggles = document.querySelectorAll('.wrapper-menu');
    const darkMode = document.getElementById('dark-mode');
    const fullscreen = document.getElementById('btnFullscreen');
    let overlay = document.querySelector('.sidebar-overlay');

    if (sidebar && !overlay) {
        overlay = document.createElement('button');
        overlay.type = 'button';
        overlay.className = 'sidebar-overlay';
        overlay.setAttribute('aria-label', 'Tutup menu');
        document.body.appendChild(overlay);
    }

    function toggleSidebar() {
        if (window.matchMedia('(max-width: 991.98px)').matches) {
            body.classList.toggle('sidebar-open');
        } else {
            body.classList.toggle('sidebar-collapsed');
            window.localStorage.setItem('inventory-sidebar-collapsed', body.classList.contains('sidebar-collapsed') ? '1' : '0');
        }
    }

    if (window.localStorage.getItem('inventory-sidebar-collapsed') === '1' &&
        !window.matchMedia('(max-width: 991.98px)').matches) {
        body.classList.add('sidebar-collapsed');
    }

    toggles.forEach((toggle) => {
        toggle.setAttribute('role', 'button');
        toggle.setAttribute('tabindex', '0');
        toggle.addEventListener('click', toggleSidebar);
        toggle.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggleSidebar();
            }
        });
    });

    overlay?.addEventListener('click', () => body.classList.remove('sidebar-open'));

    if (darkMode) {
        const savedTheme = window.localStorage.getItem('inventory-theme') ||
            (document.documentElement.getAttribute('data-theme') === 'wms-dark' ? 'dark' : 'light');
        darkMode.checked = savedTheme === 'dark';
        document.documentElement.setAttribute('data-theme', darkMode.checked ? 'wms-dark' : 'wms');
        darkMode.addEventListener('change', () => {
            const theme = darkMode.checked ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', theme === 'dark' ? 'wms-dark' : 'wms');
            window.localStorage.setItem('inventory-theme', theme);
            window.dispatchEvent(new CustomEvent('inventory:theme-changed', { detail: { theme } }));
        });
    }

    if (fullscreen) {
        fullscreen.addEventListener('click', (event) => {
            event.preventDefault();
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen?.();
            } else {
                document.exitFullscreen?.();
            }
        });
    }

    document.getElementById('loading')?.remove();

    document.querySelectorAll('.mm-sidebar a[href^="#"]').forEach((trigger) => {
        const targetId = trigger.getAttribute('href').slice(1);
        const target = targetId ? document.getElementById(targetId) : null;
        if (!target || !target.classList.contains('submenu')) return;

        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            const isOpen = target.classList.toggle('show');
            trigger.setAttribute('aria-expanded', String(isOpen));
            trigger.closest('li')?.classList.toggle('active', isOpen);
        });
    });
});
