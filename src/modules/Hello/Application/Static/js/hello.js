var SKIN_MODE_STORAGE_KEY = 'semitexa_skin_mode';

function applySkinMode(mode) {
    var resolved = mode === 'dark' ? 'dark' : 'light';
    var body = document.body;
    var darkLabel = body !== null ? body.getAttribute('data-theme-label-dark') || 'Dark mode' : 'Dark mode';
    var lightLabel = body !== null ? body.getAttribute('data-theme-label-light') || 'Light mode' : 'Light mode';
    var nextActionLabel = resolved === 'dark' ? lightLabel : darkLabel;

    document.documentElement.setAttribute('data-skin-mode', resolved);

    document.querySelectorAll('[data-skin-toggle]').forEach(function (toggle) {
        toggle.setAttribute('aria-pressed', resolved === 'dark' ? 'true' : 'false');
        toggle.setAttribute('aria-label', nextActionLabel);
    });

    document.querySelectorAll('[data-skin-text]').forEach(function (label) {
        label.textContent = nextActionLabel;
    });
}

document.querySelectorAll('[data-skin-toggle]').forEach(function (toggle) {
    toggle.addEventListener('click', function () {
        var current = document.documentElement.getAttribute('data-skin-mode') === 'dark' ? 'dark' : 'light';
        var next = current === 'dark' ? 'light' : 'dark';

        applySkinMode(next);

        try {
            window.localStorage.setItem(SKIN_MODE_STORAGE_KEY, next);
        } catch (error) {
            // Ignore storage write failures and keep the in-memory mode.
        }
    });
});

applySkinMode(document.documentElement.getAttribute('data-skin-mode'));

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
