document.querySelectorAll('.color-swatch').forEach(function (swatch) {
    swatch.addEventListener('click', function (e) {
        e.preventDefault();
        window.location.href = '/?color=' + encodeURIComponent(this.dataset.color);
    });
});
