---
title: Caching & CDN
category: Growth
summary: Operate LiteSpeed Cache and an object cache/CDN correctly — what to purge when, which pages must never be cached, and how to diagnose stale content.
updated: 2026-10-26
---

# Caching & CDN

Three layers: **page cache** (HTML), **object cache** (DB results), **CDN** (edge). A change must be purged through every layer that holds a copy.

## LiteSpeed on this site

Version 7.9.2. Settings live in option `litespeed.conf`.

```
wp option get litespeed.conf --format=json
wp litespeed-purge all
wp litespeed-purge post 123
wp litespeed-purge cssjs
wp litespeed-purge min
```

If the CLI namespace differs:

```
wp lscwp-purge all
```

Fallback through PHP:

```
wp eval 'do_action( "litespeed_purge_all" ); echo "purged";'
wp eval 'do_action( "litespeed_purge_url", "https://example.com/shop/" );'
```

## What to purge after what

| Change | Purge |
| --- | --- |
| Post/page/product edit | `post <id>`, `cssjs`, `all` if layout changed |
| Elementor save | `all` + `cssjs` |
| Theme/plugin CSS/JS | `cssjs`, `min`, `all` |
| Option/settings change | `all` |
| Menu/nav change | `all` |
| Widget/sidebar change | `all` |
| Image replace | `all` (and CDN) |
| Plugin/theme update | `all` |
| DNS/CDN change | CDN purge + `all` |

## Verify cache behaviour

```
curl -sI https://example.com/ | grep -i -E 'x-litespeed|cache-control|age'
```

- `x-litespeed-cache: hit` → served from page cache.
- `miss` → generated now and stored.
- `no-cache` / `private` → not cacheable (cart, checkout, account).

After a purge, the first request should be `miss`, the second `hit`.

## Pages that must never be cached

- `/cart/`, `/checkout/`, `/my-account/`
- WooCommerce session-dependent pages
- Password-protected pages
- Logged-in users (LiteSpeed handles by default)
- Preview URLs

Check exclusions in `cache-exc`:

```
wp eval '$c = get_option( "litespeed.conf" ); echo json_encode( $c["cache-exc"] ?? array() );'
```

## Object cache

```
wp cache type
wp cache flush
wp eval 'echo wp_using_ext_object_cache() ? "external" : "none";'
```

If Redis/Memcached is active:

- Flush after DB imports, direct SQL writes, and option changes.
- Do not flush during peak checkout unless necessary; it causes a cold-cache stampede.
- Verify the drop-in exists: `wp-content/object-cache.php`.

## CDN

If Cloudflare/QUIC.cloud sits in front:

- Purge the CDN after LiteSpeed purge; they are independent caches.
- Keep HTML cache TTL short for a store; cache static assets aggressively.
- Do not cache `/wp-admin`, `/wp-json`, `/cart`, `/checkout`, `/my-account`.
- Check for `cf-cache-status: HIT` on pages where it should be `DYNAMIC`.

## Diagnosing "my change is not showing"

Cheapest first:

1. Fetch the URL with a cache-busting query `?v=123` — if the new content appears, it is caching.
2. Check response headers for hit/miss.
3. Purge targeted, re-fetch, check headers.
4. Purge CDN.
5. Flush object cache.
6. Delete Elementor CSS (`_elementor_css` meta + `uploads/elementor/css/*`).
7. Purge `cssjs` and `min` if HTML is correct but styling is stale.
8. Check browser cache (hard reload / private window).
9. Confirm the write actually persisted (read the meta/option/file).

## Cache-safe development

- Deactivate page cache while editing templates, then re-enable and purge.
- Never test logged-in behaviour in a private window without logging in (you will see the guest cache).
- Use a staging site for plugin updates when possible.
- After enabling minify, test: home, shop, product, cart, checkout, a form page, a page with a slider.

## Gotchas

- `all` purge on a large catalog can spike origin load; use targeted purges in business hours.
- Aggressive JS defer/combine breaks Elementor interactions and WooCommerce add-to-cart; disable combine first when debugging.
- LiteSpeed's crawler warms cache; a page may be slow once after purge, then fast.
- Settings presets can reset custom exclusions; re-check `cache-exc` after switching presets.
- A CDN "development mode" left on serves uncached pages and hides real performance.
- Object cache flush does not purge page cache, and vice versa.
