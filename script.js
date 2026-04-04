// ---- FORCE SCROLL TO TOP ON LOAD ----
if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
window.scrollTo(0, 0);

// ---- NAV SCROLL EFFECT ----
const nav = document.getElementById('nav');
window.addEventListener('scroll', () => {
  nav.classList.toggle('scrolled', window.scrollY > 40);
});

// ---- MOBILE MENU ----
function toggleMenu() {
  document.getElementById('navMobile').classList.toggle('open');
}
function closeMenu() {
  document.getElementById('navMobile').classList.remove('open');
}

// ---- SCROLL REVEAL ----
const revealEls = document.querySelectorAll(
  '.service-card, .pillar, .about-content, .contact-form, .contact-info, .section-header'
);
revealEls.forEach(el => el.classList.add('reveal'));

const observer = new IntersectionObserver(
  entries => entries.forEach(e => {
    if (e.isIntersecting) {
      e.target.classList.add('visible');
      observer.unobserve(e.target);
    }
  }),
  { threshold: 0.12 }
);
revealEls.forEach(el => observer.observe(el));

// ---- STAGGER SERVICE CARDS ----
document.querySelectorAll('.service-card').forEach((card, i) => {
  card.style.transitionDelay = `${i * 0.08}s`;
});

// ---- CONTACT FORM ----
function handleSubmit(e) {
  e.preventDefault();
  const form    = e.target;
  const btn     = form.querySelector('.btn-text');
  const success = document.getElementById('formSuccess');

  btn.textContent = 'Sending…';
  form.querySelector('button[type=submit]').disabled = true;

  fetch('submit.php', {
    method: 'POST',
    body: new FormData(form),
  })
    .then(r => r.json())
    .then(data => {
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
      btn.textContent = 'Send Message';
      form.querySelector('button[type=submit]').disabled = false;
    });
}

// ---- SMOOTH ACTIVE NAV LINKS ----
const sections = document.querySelectorAll('section[id]');
const navLinks = document.querySelectorAll('.nav-links a, .nav-mobile a');

window.addEventListener('scroll', () => {
  let current = '';
  sections.forEach(s => {
    if (window.scrollY >= s.offsetTop - 120) current = s.id;
  });
  navLinks.forEach(a => {
    a.style.color = a.getAttribute('href') === `#${current}` ? '#E2E8F0' : '';
  });
}, { passive: true });
