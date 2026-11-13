/**
 * FemaleRider public website.
 *
 * Note: the realtime Echo client (./echo.js) is intentionally NOT loaded on the public
 * marketing site — visitors never subscribe to broadcast channels, and it requires the
 * laravel-echo / pusher-js packages plus Reverb credentials. Import it from a dedicated
 * entry point if an authenticated web surface ever needs it.
 */

document.documentElement.classList.add('js');

// Mobile navigation
const toggle = document.querySelector('[data-nav-toggle]');
const menu = document.querySelector('[data-nav-menu]');
if (toggle && menu) {
    const setOpen = (open) => {
        toggle.setAttribute('aria-expanded', String(open));
        menu.classList.toggle('hidden', !open);
        document.body.classList.toggle('overflow-hidden', open);
        toggle.querySelector('[data-icon-open]')?.classList.toggle('hidden', open);
        toggle.querySelector('[data-icon-close]')?.classList.toggle('hidden', !open);
    };
    toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            setOpen(false);
            toggle.focus();
        }
    });
    menu.querySelectorAll('a').forEach((a) => a.addEventListener('click', () => setOpen(false)));
    window.matchMedia('(min-width: 1024px)').addEventListener('change', (e) => e.matches && setOpen(false));
}

// Header shadow on scroll
const header = document.querySelector('[data-site-header]');
if (header) {
    const onScroll = () => header.classList.toggle('shadow-soft', window.scrollY > 8);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
}

// Reveal-on-scroll
const revealables = document.querySelectorAll('.reveal');
if ('IntersectionObserver' in window && revealables.length) {
    const io = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    io.unobserve(entry.target);
                }
            });
        },
        { rootMargin: '0px 0px -8% 0px', threshold: 0.08 },
    );
    revealables.forEach((el) => io.observe(el));
} else {
    revealables.forEach((el) => el.classList.add('is-visible'));
}

// Contact form: prevent double submission
document.querySelectorAll('form[data-once]').forEach((form) => {
    form.addEventListener('submit', () => {
        const btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.dataset.label = btn.textContent;
            btn.textContent = 'Sending…';
        }
    });
});
