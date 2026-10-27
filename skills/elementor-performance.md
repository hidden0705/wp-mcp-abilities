---
title: Elementor Performance
category: Elementor
summary: Reduce Elementor's page weight — experiments, DOM size, fonts, icons, animations, and the 4.x atomic DOM advantage, measured rather than guessed.
updated: 2026-10-26
---

# Elementor Performance

Elementor is not inherently slow; heavy pages are. Measure before changing settings.

## Baseline

```
wpmcp/audit-url { "url": "https://qadrimarket.com/", "strategy": "mobile" }
```

Then check page weight, DOM nodes and requests in DevTools. Targets: < 1.5 MB, < 1,500 DOM nodes, < 70 requests for a store homepage.

## Experiments (Elementor → Settings → Experiments)

| Experiment | Effect | Recommendation |
| --- | --- | --- |
| Optimized Asset Loading | loads CSS/JS per widget | active |
| Optimized CSS Loading | prints only used CSS | active |
| Inline Font Icons | inlines used icons | active |
| Font Awesome 4 Support | legacy icons | off if unused |
| DOM Output | removes wrapper divs | active |
| Lazy Load Background Images | defers backgrounds | active, but not the hero |
| e_optimized_markup | cleaner markup | active |

Check current state:

```
wp option list --search="elementor_experiment*" --fields=option_name,option_value --format=json
```

Change one experiment at a time, purge CSS, and test Elementor pages, WooCommerce pages and forms.

## 4.x atomic advantage

Atomic Elements render a single wrapper DIV instead of nested wrappers. On new atomic pages this reduces DOM size and CSS specificity. Existing classic pages keep the old markup until rebuilt — do not promise the gain without migrating.

## Fonts

- Limit to 2 families, 3 weights.
- Self-host or use the Font Library; avoid loading 6 Google weights.
- Disable Google Fonts if the site is EU-facing and self-host instead.
- Preload only the font used in the hero.
- `font-display: swap` avoids invisible text but can shift layout — match fallback metrics.

## Icons

- Font Awesome loads a whole library for a few icons; enable "Inline Font Icons" so only used icons ship.
- Replace icon fonts with inline SVG where possible.
- Do not load both FA4 and FA5.

## Images

- Elementor background images are CSS backgrounds — often invisible to lazy-load and `srcset`.
- Prefer an Image widget for the hero so it gets `fetchpriority` and responsive sizes.
- Set image size to the displayed size; Elementor offers registered sizes in the control.
- Enable LiteSpeed image optimization; verify WebP is served.

## Animations and effects

- Entrance animations add JS and delay content; disable on the hero and on mobile.
- Parallax and mouse-tracking effects are main-thread heavy (INP).
- Avoid animated gradients and large blur filters.
- Respect `prefers-reduced-motion`.

## DOM size

Common bloat:

- Nested sections/columns 4+ levels deep.
- A Loop Grid with 12 complex items.
- Mega menu with every category and image.
- Icon boxes repeated instead of a single list.

Simplify structure; do not just add CSS.

## CSS/JS loading

- Elementor loads widget CSS on demand with Optimized Asset Loading; verify with DevTools that unused widget CSS is absent.
- Third-party Elementor add-ons often load globally — disable unused widgets in Elementor → Settings → Features or the add-on's settings.
- Do not combine Elementor CSS with LiteSpeed "combine" blindly; test.

## Caching interaction

```
wp elementor flush-css
wp litespeed-purge cssjs
wp litespeed-purge all
```

Elementor writes per-post CSS files; a stale file after a kit/global change is the usual "styles did not update".

## WooCommerce + Elementor

- Elementor product templates can duplicate WooCommerce's own CSS; check for two price styles.
- Cart/checkout widgets from Elementor Pro bypass some WooCommerce cache exclusions — verify `/cart/` is not cached.
- Product archive templates built with Loop Grid may lose WooCommerce sorting/filtering.

## Before/after protocol

1. Audit mobile + desktop, three URLs.
2. Record LCP element, INP interactions, CLS sources.
3. Change one thing.
4. Purge all caches.
5. Re-audit the same URLs.
6. Revert if the median does not improve.

## Gotchas

- Experiments can break third-party widgets; test the checkout and forms after each change.
- "Optimized CSS Loading" can break dynamic pages if a widget's CSS is generated late.
- Lazy-loading the hero is the most common self-inflicted LCP regression.
- Elementor's own performance report is not field data; use CrUX/Search Console for ranking impact.
- Adding a second optimization plugin on top of LiteSpeed usually conflicts.
