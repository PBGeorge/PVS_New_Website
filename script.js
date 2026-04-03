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
  const btn = e.target.querySelector('.btn-text');
  btn.textContent = 'Sending…';
  setTimeout(() => {
    document.getElementById('contactForm').reset();
    btn.textContent = 'Send Message';
    document.getElementById('formSuccess').classList.add('visible');
    setTimeout(() => {
      document.getElementById('formSuccess').classList.remove('visible');
    }, 5000);
  }, 1200);
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
