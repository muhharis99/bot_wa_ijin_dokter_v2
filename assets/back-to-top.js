document.addEventListener('DOMContentLoaded', function () {
    const button = document.createElement('button');

    button.type = 'button';
    button.id = 'backToTop';
    button.setAttribute('aria-label', 'Kembali ke atas');
    button.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>';
    button.style.position = 'fixed';
    button.style.right = '20px';
    button.style.bottom = '20px';
    button.style.width = '44px';
    button.style.height = '44px';
    button.style.display = 'none';
    button.style.alignItems = 'center';
    button.style.justifyContent = 'center';
    button.style.border = '0';
    button.style.borderRadius = '50%';
    button.style.background = '#0d6e4f';
    button.style.color = '#ffffff';
    button.style.boxShadow = '0 4px 14px rgba(13, 110, 79, 0.28)';
    button.style.cursor = 'pointer';
    button.style.zIndex = '1040';
    button.style.padding = '0';

    document.body.appendChild(button);

    function updateVisibility() {
        button.style.display = window.scrollY > 300 ? 'flex' : 'none';
    }

    window.addEventListener('scroll', updateVisibility, { passive: true });

    button.addEventListener('click', function () {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });

    updateVisibility();
});
