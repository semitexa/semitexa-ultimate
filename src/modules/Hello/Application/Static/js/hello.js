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
