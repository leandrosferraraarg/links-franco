document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-confirm]');
    if (button && !window.confirm(button.dataset.confirm)) event.preventDefault();
});

function prepareImages(container) {
    container.querySelectorAll('img[data-fallback]').forEach((image) => {
        const fallback = () => {
            if (image.dataset.retried) { image.style.visibility = 'hidden'; return; }
            image.dataset.retried = '1';
            image.src = image.dataset.fallback;
        };
        image.addEventListener('error', fallback);
        if (image.complete && !image.naturalWidth) fallback();
    });
}
prepareImages(document);

const mosaic = document.querySelector('.mosaic');
const nextLink = document.querySelector('[data-next]');
if (mosaic && nextLink && 'IntersectionObserver' in window) {
    const navigation = nextLink.closest('.pagination');
    const status = navigation.querySelector('.load-status');
    const seen = new Set([...mosaic.querySelectorAll('.card')].map(card => card.dataset.id));
    let busy = false;
    let finished = false;
    const observer = new IntersectionObserver(entries => {
        if (entries.some(entry => entry.isIntersecting)) loadMore();
    }, { rootMargin: '0px 0px 400px 0px' });

    async function loadMore() {
        if (busy || finished) return;
        busy = true;
        observer.unobserve(navigation);
        nextLink.setAttribute('aria-disabled', 'true');
        mosaic.setAttribute('aria-busy', 'true');
        status.textContent = 'Cargando…';
        const requestedUrl = new URL(nextLink.href);
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(requestedUrl, { signal: controller.signal });
            if (!response.ok) throw new Error('No se pudo cargar');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const incoming = page.querySelector('.mosaic');
            if (!incoming) throw new Error('Respuesta incompleta');
            const fragment = document.createDocumentFragment();
            incoming.querySelectorAll('.card').forEach(card => {
                if (!seen.has(card.dataset.id)) {
                    seen.add(card.dataset.id);
                    fragment.append(document.importNode(card, true));
                }
            });
            prepareImages(fragment);
            mosaic.append(fragment);
            const following = page.querySelector('[data-next]');
            const followingUrl = following && new URL(following.getAttribute('href'), requestedUrl);
            if (followingUrl && followingUrl.href !== requestedUrl.href) {
                nextLink.href = followingUrl.href;
                status.textContent = '';
                nextLink.textContent = 'Cargar más';
                observer.observe(navigation);
            } else {
                finished = true;
                nextLink.hidden = true;
                status.textContent = '';
                observer.disconnect();
            }
        } catch {
            status.textContent = 'No se pudo cargar. Tocá Reintentar.';
            nextLink.textContent = 'Reintentar';
        } finally {
            clearTimeout(timeout);
            busy = false;
            nextLink.removeAttribute('aria-disabled');
            mosaic.removeAttribute('aria-busy');
        }
    }
    nextLink.addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        loadMore();
    });
    observer.observe(navigation);
}
