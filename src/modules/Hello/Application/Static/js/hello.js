var THEME_STORAGE_KEY = window.SEMITEXA_DEMO_THEME_KEY || 'semitexa_demo_theme';

function applyTheme(theme) {
    var resolvedTheme = theme === 'dark' ? 'dark' : 'light';
    var root = document.documentElement;
    var body = document.body;
    var darkLabel = body !== null ? body.getAttribute('data-theme-label-dark') || 'Dark mode' : 'Dark mode';
    var lightLabel = body !== null ? body.getAttribute('data-theme-label-light') || 'Light mode' : 'Light mode';

    root.setAttribute('data-demo-theme', resolvedTheme);

    document.querySelectorAll('[data-demo-theme-toggle]').forEach(function (toggle) {
        toggle.setAttribute('aria-pressed', resolvedTheme === 'dark' ? 'true' : 'false');
    });

    document.querySelectorAll('[data-demo-theme-text]').forEach(function (label) {
        label.textContent = resolvedTheme === 'dark' ? lightLabel : darkLabel;
    });
}

document.querySelectorAll('[data-demo-theme-toggle]').forEach(function (toggle) {
    toggle.addEventListener('click', function () {
        var currentTheme = document.documentElement.getAttribute('data-demo-theme') === 'dark' ? 'dark' : 'light';
        var nextTheme = currentTheme === 'dark' ? 'light' : 'dark';

        applyTheme(nextTheme);

        try {
            window.localStorage.setItem(THEME_STORAGE_KEY, nextTheme);
        } catch (error) {
            // Ignore storage write failures and keep the in-memory theme.
        }
    });
});

applyTheme(document.documentElement.getAttribute('data-demo-theme'));

var lockup = document.querySelector('[data-logo-lockup]');

if (lockup !== null) {
    document.addEventListener('pointermove', function (event) {
        var width = window.innerWidth || 1;
        var height = window.innerHeight || 1;
        var rotateY = ((event.clientX / width) - 0.5) * 8;
        var rotateX = ((event.clientY / height) - 0.5) * -8;

        lockup.style.transform = 'perspective(1200px) rotateX(' + rotateX.toFixed(2) + 'deg) rotateY(' + rotateY.toFixed(2) + 'deg)';
    });

    document.addEventListener('pointerleave', function () {
        lockup.style.transform = '';
    });
}
