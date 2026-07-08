/* ============================================================
   Power Vantage Solutions — interactions
   scroll reveals · count-up · sticky-nav blur · mobile menu · form
   ============================================================ */

// ---- Force scroll to top on load ----
if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
window.scrollTo(0, 0);

// ---- Sticky-nav blur (toggled past 20px) ----
const nav = document.getElementById('nav');
const onNavScroll = () => nav.classList.toggle('scrolled', window.scrollY > 20);
window.addEventListener('scroll', onNavScroll, { passive: true });
onNavScroll();

// ---- Mobile menu ----
function toggleMenu() {
  document.getElementById('navMobile').classList.toggle('open');
}
function closeMenu() {
  document.getElementById('navMobile').classList.remove('open');
}

// ---- Scroll reveal ----
const revealObserver = new IntersectionObserver(
  (entries) => entries.forEach((e) => {
    if (e.isIntersecting) {
      e.target.classList.add('is-visible');
      revealObserver.unobserve(e.target);
    }
  }),
  { threshold: 0.12, rootMargin: '0px 0px -8% 0px' }
);
document.querySelectorAll('[data-reveal]').forEach((el) => revealObserver.observe(el));

// ---- Count-up (runs once when ~60% visible) ----
function countUp(el) {
  const target = parseFloat(el.getAttribute('data-count')) || 0;
  const suffix = el.getAttribute('data-count-suffix') || '';
  const dur = 1400;
  const start = performance.now();
  const step = (now) => {
    const p = Math.min((now - start) / dur, 1);
    const eased = 1 - Math.pow(1 - p, 3);
    el.textContent = Math.round(target * eased) + suffix;
    if (p < 1) requestAnimationFrame(step);
  };
  requestAnimationFrame(step);
}

const countObserver = new IntersectionObserver(
  (entries) => entries.forEach((e) => {
    if (e.isIntersecting) {
      countUp(e.target);
      countObserver.unobserve(e.target);
    }
  }),
  { threshold: 0.6 }
);
document.querySelectorAll('[data-count]').forEach((el) => countObserver.observe(el));

// ---- Contact form → submit.php ----
function handleSubmit(e) {
  e.preventDefault();
  const form    = e.target;
  const btn     = form.querySelector('.btn-text');
  const submit  = form.querySelector('button[type=submit]');
  const success = document.getElementById('formSuccess');
  const original = btn.textContent;

  btn.textContent = 'Sending…';
  submit.disabled = true;

  fetch('submit.php', {
    method: 'POST',
    body: new FormData(form),
  })
    .then((r) => r.json())
    .then((data) => {
      if (data.success) {
        form.reset();
        success.classList.add('visible');
        setTimeout(() => success.classList.remove('visible'), 6000);
      } else {
        alert(data.error || 'Something went wrong. Please try again.');
      }
    })
    .catch(() => {
      alert('Network error. Please check your connection and try again.');
    })
    .finally(() => {
      btn.textContent = original;
      submit.disabled = false;
    });
}
