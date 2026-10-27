---
title: Performance & Core Web Vitals
category: Growth
summary: Improve LCP, INP and CLS with a measurement-first method — server, cache, images, fonts, JS, and the WordPress/Elementor/WooCommerce specifics that actually move the needle.
updated: 2026-10-26
---

# Performance & Core Web Vitals

## Current thresholds (field data, 75th percentile)

| Metric | Good | Needs work | Poor |
| --- | --- | --- | --- |
| LCP | ≤ 2.5 s | 2.5–4.0 s | > 4.0 s |
| INP | ≤ 200 ms | 200–500 ms | > 500 ms |
| CLS | ≤ 0.1 | 0.1–0.25 | > 0.25 |

INP replaced FID in 2024. Lab scores (Lighthouse) approximate; ranking uses field data (CrUX/Search Console).

## Measure before changing

- `wpmcp/audit-url` for mobile + desktop, homepage + a product page + checkout.
- Search Console → Core Web Vitals for field data by URL group.
- Query Monitor for PHP queries, hooks and slow plugins (temporary, remove after).
- Server TTFB: `curl -o /dev/null -s -w '%{time_starttransfer}\n' https://example.com/`

If TTFB > 800 ms, fix hosting/cache before touching images or JS.

## Fix order (highest return first)

1. **TTFB / hosting** — PHP 8.2+, object cache, page cache.
2. **Page cache** — LiteSpeed serves HTML without booting WordPress.
3. **Images** — correct dimensions, WebP/AVIF, lazy load below the fold, no lazy load on the LCP image.
4. **Fonts** — self-host, `font-display: swap`, preload only the used weights, avoid 6 weights.
5. **CSS/JS** — remove unused plugin assets per page, defer non-critical JS, avoid combine if it breaks.
6. **DOM size** — Elementor loops and mega menus; aim < 1,500 nodes.
7. **Third parties** — tracking, chat, pixels; each is a main-thread cost.

## LCP specifics

The LCP element is usually a hero image, a product image, or an H1.

- Do not lazy-load the hero: `fetchpriority="high"`, no `loading="lazy"`.
- Preload the hero image if it is a CSS background or injected late.
- Serve the exact displayed size (a 2000px image in a 600px slot wastes 3× bytes).
- Avoid Elementor background images on the hero when a real `<img>` is possible; `<img>` is discoverable earlier.
- Check the HTML order: the LCP image should not be inserted by JS after hydration.

## INP specifics

INP is about responsiveness after load.

- Break up long tasks (>50 ms) in theme/plugin JS.
- Remove or defer chat widgets until interaction.
- Avoid heavy `scroll`/`mousemove` handlers without `requestAnimationFrame`.
- Elementor animations and sliders add main-thread work; limit them on mobile.
- WooCommerce cart fragments (`wc-cart-fragments`) run on every page — dequeue where the cart widget is not shown:

```php
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_cart() && ! is_checkout() ) {
        wp_dequeue_script( 'wc-cart-fragments' );
    }
}, 99 );
```

## CLS specifics

- Set explicit `width`/`height` (or `aspect-ratio`) on images and embeds.
- Reserve space for banners, cookie notices and injected ads.
- Avoid inserting content above existing content after load.
- `font-display: swap` can cause a small shift; `optional` or matched fallback metrics reduce it.
- Elementor popups that push layout instead of overlaying cause CLS.

## Caching stack on this site

- LiteSpeed Cache: page cache, CSS/JS minify, lazy load, image optimization.
- Object cache: Redis/Memcached if the host provides it (`wp cache type`).
- CDN: optional; purge both CDN and LiteSpeed after changes.

See `caching-and-cdn` for settings and purge commands.

## Database and PHP

```
wp db query "SELECT COUNT(*) FROM wp_postmeta" --format=json
wp transient delete --expired
wp db optimize
wp eval 'echo PHP_VERSION;'
```

- Autoloaded options > 1 MB slow every request. Audit and disable unused autoload.
- Revisions and orphaned meta add weight; clean periodically.
- PHP 8.2/8.3 is a real speedup over 7.4; this site is on 8.2.

## Plugin-level wins

- Disable unused modules inside plugins (Elementor experiments, WooCommerce features).
- Remove plugins that load assets on every page but are used on one.
- Replace heavy sliders with static heroes.
- Limit Elementor add-on packs; each adds CSS/JS.

## WooCommerce specifics

- HPOS reduces order-query load; this store is HPOS-era.
- Exclude cart, checkout, my-account from page cache.
- Do not cache the mini-cart; use ESI or AJAX.
- Keep `wc-cart-fragments` off non-shop pages.
- Product object caching is on by default for new stores in WooCommerce 11+.

## Regression checks

After any performance change:

1. Re-run `wpmcp/audit-url` on the same three URLs/strategy.
2. Confirm visual parity on mobile and desktop.
3. Add to cart, open cart, complete a test checkout.
4. Check console for new errors.
5. Purge all caches and re-test once, because the first hit is uncached.

## Gotchas

- Lab scores fluctuate ±10; compare medians of three runs, not one.
- Minify/combine can break Elementor and WooCommerce; test cart and product pages, not just home.
- Lazy-loading everything makes LCP worse.
- A CDN in front of LiteSpeed can serve stale HTML after a purge — purge both.
- Adding "performance" plugins on top of LiteSpeed usually conflicts.
