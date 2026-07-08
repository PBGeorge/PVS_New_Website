# Handoff: Power Vantage Solutions — Website Redesign (light, modern, animated)

## Overview
A full-page redesign of the Power Vantage Solutions marketing site (AI Agents & Copilot
Studio consultancy). Same content and sections as the current live site, restyled to a
warm, friendly, light-modern direction with blue / teal / gold pulled from the logo, and
"moderate" motion (scroll reveals, an animated orbit hero, a count-up stat, sticky-nav
blur, hover lifts).

Sections, in order: **Nav → Hero → Services → Use Cases → How It Works → About → Contact → Footer.**

## About the Design Files
The file in this bundle (`Power Vantage Solutions.dc.html`) is a **design reference created
in HTML** — a working prototype showing the intended look, layout, and behavior. It is **not
production code to copy directly**: it renders through a component runtime and is inline-styled
for prototyping.

**The task is to recreate this design in the existing site's environment**, which is plain
static **HTML + CSS + vanilla JS** deployed via cPanel Git (`.cpanel.yml`). Rebuild it as:
- `index.html` (semantic markup, one section per block)
- `style.css` (move the inline styles into classes — the current site already uses a class-based `style.css`, mirror that structure)
- `script.js` (the small amount of JS below)

**Critically, preserve everything the current `index.html` already does** — do NOT lose these
when swapping in the new look:
- All SEO `<head>` tags: `<title>`, `<meta name="description">`, canonical, Open Graph, Twitter, and the `ProfessionalService` **JSON-LD** block.
- The **EN / RO language toggle** driven by `data-i18n` attributes + `translations.js` (`toggleLang()`), and mobile menu (`toggleMenu()`/`closeMenu()`). Re-apply `data-i18n` keys to the new markup using the same keys already in `translations.js`.
- The **contact form** posting to `submit.php` (`handleSubmit(event)` + success state). The prototype's form is inert (`onsubmit="return false"`) — wire it back to `submit.php`.
- `favicon.ico`, `apple-touch-icon.png`, `og-image.png`, `logo.png`, `robots.txt`, `sitemap.xml`.

## Fidelity
**High-fidelity (hifi).** Colors, typography, spacing, radii, and interactions are final.
Recreate pixel-for-pixel, then layer the existing i18n + form plumbing back on.

## Design Tokens

**Fonts:** Google Font **Plus Jakarta Sans** (weights 400, 500, 600, 700, 800).
`font-family: 'Plus Jakarta Sans', system-ui, sans-serif;` `-webkit-font-smoothing: antialiased;`
(Current site uses Inter — this redesign switches to Plus Jakarta Sans.)

**Colors**
- Page background (warm off-white): `#FAF8F4`
- Alternate section background (white): `#FFFFFF`
- Card fill on warm sections: `#FBF9F4`
- Text (near-black): `#1A1D24`
- Muted text: `#5C6270`  · Faint/label text: `#8A909C`
- Brand blue: `#1B8DD1`  · Brand teal: `#22B39A` (darker teal for text/icons: `#1a9e88`)
- Brand gold: `#F3C64B` (darker gold for text/icons: `#C79A17`)
- Hairline border: `rgba(26,29,36,0.07)` (cards) / `rgba(26,29,36,0.10)` (dividers)
- Primary gradient (buttons, accents): `linear-gradient(120deg, #1B8DD1, #22B39A)`
- Headline gradient: `linear-gradient(120deg, #1B8DD1, #22B39A 55%, #F3C64B)` clipped to text
- Danger dot (tickets): `#E5484D`

**Radii:** cards `22px`; icon tiles `13–15px`; pills/buttons `100px`; center logo node `27px`.
**Shadows:** card hover `0 18px 44px rgba(26,29,36,.1)`; primary button `0 12px 28px rgba(27,141,209,.3)`; floating pill `0 10px 24px rgba(26,29,36,.12)`; center node `0 16px 40px rgba(26,29,36,.16)`.
**Layout:** max content width `1180px`, centered, `padding: 0 40px`. Section vertical padding `110px`. Grid gap `20–22px`.
**Type scale:** hero H1 `56px/1.06`, `-0.025em`, weight 800; section H2 `40px/1.15`, `-0.02em`, 800; card H3 `18–19px`, 700; body `14–18px`; section tag pill `11.5px`, 700, uppercase, `0.12em`.

## Screens / Views (single page)

### Nav (fixed, top)
- `position: fixed`, full width, z-index 100, transparent initially.
- On scroll past 20px: background `rgba(250,248,244,.85)`, `backdrop-filter: blur(20px)`, bottom border `rgba(26,29,36,.07)`, shadow `0 1px 22px rgba(26,29,36,.06)`.
- Left: `logo.png` (36×36) + "Power Vantage Solutions" (15px/700). Right: text links Services, Use Cases, How It Works, About (14px/500, `#5C6270`); a **Contact** pill (gradient fill, white); an **EN / RO** toggle pill (blue, `rgba(27,141,209,.09)` fill).

### Hero
- Two-column grid `1.05fr 1fr`, `padding: 150px 40px 96px`, gap 40px.
- **Left:** badge pill ("✨/dot + AI Agents & Copilot Studio Specialists"), gradient H1 "Bring the Power of AI into your business", 18px muted sub-paragraph, two buttons (**See AI in Action →** gradient primary; **Start a Project** white outline), then a stat row: *Copilot Studio / Agents & Assistants*, *Generative AI / Integrations*, *Power Platform / Apps & Automation* (15px/700 + 11.5px uppercase caption, 1px dividers).
- **Right — orbit constellation (signature animation), 500px tall, centered:**
  - Three concentric rings: 380px (blue `rgba(27,141,209,.15)`), 270px (teal `rgba(34,179,154,.2)`), 164px (gold `rgba(243,198,75,.38)`), 1.5px solid, centered via `translate(-50%,-50%)`.
  - Three orbiting **dots** on those rings (blue 16px @22s, teal 14px @16s reverse, gold 12px @11s), each a full-size rotating layer with the dot pinned at top.
  - Three orbiting **labels** on an outer 480px orbit @34s, evenly spread via `animation-delay: 0 / -11.3s / -22.6s`: **Copilot Studio** (blue dot), **Power Apps** (gold dot), **Power Automate** (teal dot). Each is a white pill (`0 10px 24px` shadow). Labels stay upright via a counter-rotating inner wrapper (`orbitAnti`, same duration + delay).
  - Center: `logo.png` (54px) in a 94px white rounded-27px node with `pulseNode` scale + a `halo` radial pulse behind.
  - Scroll hint at section bottom: "Scroll to explore" + a 1px gradient bar with `scrollPulse`.

### Services (`#services`, white bg)
Centered header (tag "What We Do" blue). 3-column grid, 6 cards (`#FBF9F4`, radius 22, border hairline, padding 30). **AI-first order:** Copilot Agents (teal icon), AI Integrations (blue), Power Automate (gold), Power Apps (blue), Power Platform Strategy (teal), then a **gradient CTA card** ("Not sure where to start?" + white "Book a Free Discovery Call →" button). Each card: 52px icon tile (tinted bg + line icon), H3, muted paragraph, bullet list (5px dot + 13px text).

### Use Cases (`#use-cases`, warm bg)
Header tag "AI In Practice" (teal). 3-column grid, 6 white cards: Customer Support Agent, Internal Knowledge Assistant, Sales & CRM Assistant, Document & Invoice Processing, Meeting & Reporting Insights, Employee Onboarding Agent. Each: 50px icon tile + H3 + paragraph.

### How It Works (`#process`, white bg)
Header tag "How We Work" (gold). 4-column grid, cards `#FBF9F4`: 01–04 in gradient-clipped 30px numerals, bold step title, description. Steps: Discover, Design, Build & Integrate, Support & Scale.

### About (`#about`, warm bg)
Centered header + lead paragraph. A white **stats bar** (flex, 1px dividers): **50+** (count-up) / Projects Delivered; AI & Power Platform / Specialists; End-to-End / Delivery Approach; Europe & Remote / Available Globally. Then 3 white **pillar** cards with icon tiles: Fast Delivery (gold), Enterprise Grade (blue shield-check), True Partnership (teal people).

### Contact (`#contact`, white bg)
Two soft radial glows behind (blue top-right, teal bottom-left). Grid `1fr 1.2fr`, gap 72px. Left: tag "Get In Touch", H2 "Let's Build Something **Powerful**" (gradient word), paragraph, and two contact rows (icon tile + text): `hello@powervantagesolutions.com`, `Europe & Remote`. Right: form card (`#FBF9F4`, radius 22, padding 36) — Name + Company row, Email, Service `<select>` (Copilot Agents / AI Integrations / Power Automate / Power Apps / Power Platform Strategy / Not sure yet), Message textarea, gradient **Send Message →** button. Inputs: white, 1.5px border `rgba(26,29,36,.12)`, radius 11; **focus** → border `#1B8DD1` + `0 0 0 3px rgba(27,141,209,.14)`.

### Footer (`#FAF8F4`)
Row: logo + name (left) · "© 2026 Power Vantage Solutions. All rights reserved." · links Services / Use Cases / About / Contact.

## Interactions & Behavior
- **Scroll reveal:** every major block starts `opacity:0; transform:translateY(26px)` and transitions to `opacity:1; transform:none` over `0.7s cubic-bezier(.2,.7,.2,1)` when it enters the viewport (IntersectionObserver, threshold ~0.12, `rootMargin: 0px 0px -8% 0px`). Cards in a grid get staggered `transition-delay` of `.06s`/`.12s`/`.18s`.
- **Count-up:** the `50+` stat animates 0→50 over 1400ms (ease-out cubic) when the stats bar is ~60% visible.
- **Sticky-nav blur:** toggled at `window.scrollY > 20` (see Nav).
- **Hover lift:** service/use-case/process/pillar cards raise `translateY(-6px)` with `0 18px 44px rgba(26,29,36,.1)` shadow.
- **Input focus** ring as described in Contact.
- **Tweakable behavior (implemented as a prop in the prototype):** *Static orbit labels* — when on (default), the three label rings are paused (`animation-play-state: paused`) so Copilot Studio / Power Apps / Power Automate hold position while the inner dots keep orbiting; when off, the labels orbit too. Expose this as you see fit (or just ship it static).
- **Reduced motion:** honor `prefers-reduced-motion: reduce` by disabling the orbit/float/reveal animations.

## Animations (keyframes to port)
`floaty`/`floaty2` (±13px/11px Y), `fadeUp` (22px up + fade), `typing` (3-dot bounce — only if you keep a chat element; not used in this hero), `orbitSpin`/`orbitSpinR` (`translate(-50%,-50%) rotate(±360deg)`), `orbitAnti` (`rotate(-360deg)` for upright labels), `pulseNode` (scale 1→1.06), `halo` (scale .85→1.7, fade out), `scrollPulse` (scaleY + opacity). Durations: dots 22/16/11s, label orbit 34s, node/halo 3.4s.

## State Management
Minimal. No app state beyond: nav scrolled flag (from scroll position), reveal "seen" per element (unobserve after first reveal), count-up run-once flag, and the static-labels boolean. Language (EN/RO) state stays in the existing `translations.js` mechanism.

## Assets
- `logo.png` — the interlocking blue/teal/gold mark (used in nav 36px, hero center node 54px, footer 30px). Included in this bundle; already present at your site root.
- All icons are inline **line SVGs** (stroke `currentColor`, 1.8 width) — no external icon library. Reuse the paths from the prototype file.
- Keep existing `favicon.ico`, `apple-touch-icon.png`, `og-image.png`.

## Files
- `Power Vantage Solutions.dc.html` — the full design reference (all sections + logic). Read its inline styles and the `<script>` logic class (`applyLabels`, `countUp`, IntersectionObserver setup, nav scroll) for exact values.
- `logo.png` — logo asset.

## Deploy (existing cPanel Git flow)
Your repo already has `.cpanel.yml`. Once the new `index.html` / `style.css` / `script.js`
are committed to the branch cPanel watches, push and cPanel's deployment task copies them to
your `public_html`. No build step is required (static site). Bump the `?v=` query on
`style.css` to bust cache.
