/* ============================================================
   Power Vantage Solutions — interactions
   header progress · reveals · chat demo · use-case carousel ·
   process scroll-link · mobile menu · contact form
   ============================================================ */

// ---- Force scroll to top on load ----
if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
window.scrollTo(0, 0);

const reduceMotion = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
if (!reduceMotion) document.body.classList.add('animated');

// ---- Mobile menu ----
function toggleMenu() {
  document.getElementById('navMobile').classList.toggle('open');
}
function closeMenu() {
  document.getElementById('navMobile').classList.remove('open');
}

// ---- Header scroll progress ----
const progressEl = document.getElementById('navProgress');
function onScroll() {
  const se = document.scrollingElement || document.documentElement;
  const p = se.scrollTop / Math.max(1, se.scrollHeight - innerHeight);
  if (progressEl) progressEl.style.transform = `scaleX(${Math.min(1, Math.max(0, p))})`;
  updateProcess();
}
window.addEventListener('scroll', onScroll, { passive: true });
window.addEventListener('resize', onScroll);

// ---- Scroll reveal ----
const revealObserver = new IntersectionObserver(
  (entries) => entries.forEach((e) => {
    if (e.isIntersecting) {
      e.target.classList.add('is-visible');
      revealObserver.unobserve(e.target);
    }
  }),
  { threshold: 0.1, rootMargin: '0px 0px -6% 0px' }
);
document.querySelectorAll('[data-reveal]').forEach((el) => revealObserver.observe(el));

// ---- Process scroll-linked progress ----
const processSteps = document.getElementById('processSteps');
const processFill = document.getElementById('processFill');
function updateProcess() {
  if (!processSteps || !processFill) return;
  const r = processSteps.getBoundingClientRect();
  const q = Math.max(0, Math.min(1, (innerHeight * 0.55 - r.top) / r.height));
  processFill.style.transform = `scaleY(${q})`;
  processSteps.querySelectorAll('[data-step]').forEach((n) => {
    n.classList.toggle('is-active', n.getBoundingClientRect().top < innerHeight * 0.55);
  });
}
onScroll();

/* ============================================================
   Hero chat simulation (decorative)
   ============================================================ */
const SCRIPTS = [
  { channel: 'Website chat · Support', steps: [
    ['user', 'Hi — invoice INV-2207 has the wrong VAT number on it.'],
    ['agent', 'Thanks, Ana. I found INV-2207 on your account and corrected the VAT number.'],
    ['action', 'Dataverse updated · Corrected invoice emailed'],
    ['agent', 'Done. Anything else I can help with?'] ] },
  { channel: 'Microsoft Teams · HR', steps: [
    ['user', 'How many holiday days do I have left this year?'],
    ['agent', 'You have 12 days left for 2026. Want me to book some?'],
    ['user', 'Yes — 3 to 7 November.'],
    ['action', 'Leave request sent to your manager'] ] },
  { channel: 'Outlook · Finance', steps: [
    ['user', 'Forwarded: 14 supplier invoices (PDF)'],
    ['agent', "Extracted totals, dates and PO numbers from all 14. Two don't match an open PO."],
    ['action', '12 posted to ERP · 2 flagged for review'] ] }
];

const chatBody = document.getElementById('chatBody');
const chatChannel = document.getElementById('chatChannel');
const chatScenario = document.getElementById('chatScenario');

function bubbleFor(role, text) {
  const wrap = document.createElement('div');
  wrap.className = 'chat-msg';
  if (role === 'user') {
    wrap.innerHTML = `<div class="bubble-user"></div>`;
    wrap.firstChild.textContent = text;
  } else if (role === 'agent') {
    wrap.innerHTML = `<div class="bubble-agent"></div>`;
    wrap.firstChild.textContent = text;
  } else {
    wrap.innerHTML = `<div class="bubble-action"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><span></span></div>`;
    wrap.querySelector('span').textContent = text;
  }
  return wrap;
}

let chatTimer = null;
function runChat() {
  if (!chatBody) return;
  let si = 0, k = 0;
  const next = () => {
    const sc = SCRIPTS[si];
    if (k === 0) {
      chatBody.innerHTML = '';
      if (chatChannel) chatChannel.textContent = sc.channel;
      if (chatScenario) chatScenario.textContent = `${si + 1} / ${SCRIPTS.length}`;
    }
    if (k >= sc.steps.length) {
      chatTimer = setTimeout(() => {
        const fadeOut = chatBody.animate(
          [{ opacity: 1, transform: 'none', filter: 'blur(0)' }, { opacity: 0, transform: 'translateY(-10px)', filter: 'blur(4px)' }],
          { duration: reduceMotion ? 0 : 320, easing: 'ease-in', fill: 'forwards' }
        );
        fadeOut.onfinish = () => { si = (si + 1) % SCRIPTS.length; k = 0; fadeOut.cancel(); next(); };
      }, 3000);
      return;
    }
    const [role, text] = sc.steps[k]; k++;
    const push = () => chatBody.appendChild(bubbleFor(role, text));
    if (role === 'agent') {
      chatTimer = setTimeout(() => {
        const typing = document.createElement('div');
        typing.className = 'typing-indicator';
        typing.innerHTML = '<span></span><span></span><span></span>';
        chatBody.appendChild(typing);
        chatTimer = setTimeout(() => {
          typing.remove();
          push();
          chatTimer = setTimeout(next, 1500);
        }, 1200);
      }, 350);
    } else {
      chatTimer = setTimeout(() => { push(); chatTimer = setTimeout(next, role === 'user' ? 500 : 1300); }, k === 1 ? 700 : 400);
    }
  };
  next();
}
if (chatBody) {
  if (reduceMotion) {
    chatBody.innerHTML = '';
    SCRIPTS[0].steps.forEach(([role, text]) => chatBody.appendChild(bubbleFor(role, text)));
    if (chatChannel) chatChannel.textContent = SCRIPTS[0].channel;
    if (chatScenario) chatScenario.textContent = `1 / ${SCRIPTS.length}`;
  } else {
    runChat();
  }
}

/* ============================================================
   Use-case carousel — native horizontal scroll + snap
   ============================================================ */
(function initCarousel() {
  const view = document.getElementById('ucCarousel');
  const track = document.getElementById('ucTrack');
  const prevBtn = document.getElementById('ucPrev');
  const nextBtn = document.getElementById('ucNext');
  const rail = document.querySelector('.carousel-rail');
  const thumb = document.getElementById('ucThumb');
  if (!view || !track) return;

  view.style.overflowX = 'auto';
  view.style.scrollSnapType = 'x proximity';
  view.style.scrollBehavior = 'smooth';
  view.style.scrollbarWidth = 'none';
  track.querySelectorAll('.uc-card').forEach((c) => (c.style.scrollSnapAlign = 'start'));

  function cardStep() {
    const c = track.children[0];
    if (!c) return 400;
    const gap = parseFloat(getComputedStyle(track).columnGap) || 16;
    return c.getBoundingClientRect().width + gap;
  }
  function updateThumb() {
    if (!rail || !thumb) return;
    const maxScroll = track.scrollWidth - view.clientWidth;
    const ratio = Math.min(1, view.clientWidth / track.scrollWidth);
    const railW = rail.clientWidth;
    const w = Math.max(24, railW * ratio);
    const p = maxScroll <= 0 ? 0 : view.scrollLeft / maxScroll;
    thumb.style.width = w + 'px';
    thumb.style.transform = `translateX(${p * (railW - w)}px)`;
  }
  view.addEventListener('scroll', () => requestAnimationFrame(updateThumb), { passive: true });
  window.addEventListener('resize', updateThumb);
  if (prevBtn) prevBtn.addEventListener('click', () => view.scrollBy({ left: -cardStep(), behavior: 'smooth' }));
  if (nextBtn) nextBtn.addEventListener('click', () => view.scrollBy({ left: cardStep(), behavior: 'smooth' }));
  updateThumb();
})();

/* ============================================================
   Contact form — validation + submit.php
   ============================================================ */
(function initContactForm() {
  const form = document.getElementById('contactForm');
  if (!form) return;
  const success = document.getElementById('contactSuccess');
  const successName = document.getElementById('successName');
  const resetBtn = document.getElementById('contactReset');
  const errName = document.getElementById('errName');
  const errEmail = document.getElementById('errEmail');
  const errMessage = document.getElementById('errMessage');
  const nameInput = document.getElementById('pv-name');
  const emailInput = document.getElementById('pv-email');
  const msgInput = document.getElementById('pv-msg');

  function t(key) {
    return (typeof strings !== 'undefined' && strings[key]) || key;
  }

  function validateField(input, errEl, test, key) {
    const ok = test(input.value.trim());
    if (errEl) {
      errEl.textContent = ok ? '' : t(key);
      errEl.classList.toggle('visible', !ok);
    }
    return ok;
  }
  function validateName() { return validateField(nameInput, errName, (v) => v.length > 0, 'contact.form.errName'); }
  function validateEmail() { return validateField(emailInput, errEmail, (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v), 'contact.form.errEmail'); }
  function validateMessage() { return validateField(msgInput, errMessage, (v) => v.length >= 10, 'contact.form.errMessage'); }

  nameInput.addEventListener('blur', validateName);
  emailInput.addEventListener('blur', validateEmail);
  msgInput.addEventListener('blur', validateMessage);

  window.handleSubmit = function (e) {
    e.preventDefault();
    const okName = validateName(), okEmail = validateEmail(), okMessage = validateMessage();
    if (!okName || !okEmail || !okMessage) return false;

    const btn = form.querySelector('button[type=submit]');
    const btnText = form.querySelector('.btn-text');
    const original = btnText.textContent;
    btnText.textContent = t('contact.form.sending');
    btn.disabled = true;

    const firstName = (nameInput.value.trim().split(/\s+/)[0]) || 'there';

    fetch('submit.php', { method: 'POST', body: new FormData(form) })
      .then((r) => r.json())
      .then((data) => {
        if (data.success) {
          if (successName) successName.textContent = firstName;
          form.hidden = true;
          if (success) success.hidden = false;
        } else {
          alert(data.error || t('contact.form.error'));
        }
      })
      .catch(() => alert(t('contact.form.networkError')))
      .finally(() => {
        btnText.textContent = original;
        btn.disabled = false;
      });
    return false;
  };

  if (resetBtn) {
    resetBtn.addEventListener('click', () => {
      form.reset();
      [errName, errEmail, errMessage].forEach((el) => { if (el) { el.textContent = ''; el.classList.remove('visible'); } });
      form.hidden = false;
      if (success) success.hidden = true;
    });
  }
})();
