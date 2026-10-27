---
title: WordPress Expert Playbook
category: Core
summary: Entry point and decision tree for running this site like a senior WordPress operator — pre-flight, risk classes, verification loop, and which of the 45 skill playbooks to load next.
updated: 2026-10-26
---

# WordPress Expert Playbook

Load this first. It tells you how to work safely on this site, how to verify, and which playbook covers the task.

## Site profile (verify, do not assume forever)

| Item | Value at last check |
| --- | --- |
| WordPress | 7.1.3 (7.2 in beta as of Oct 2026) |
| WooCommerce | 11.2.1, HPOS-era, block cart/checkout default for new stores |
| Elementor | 4.3.5 (Atomic features available; existing pages still classic) |
| PRO Elements | 4.3.2 |
| Cache | LiteSpeed Cache 7.9.2 |
| Forms | SureForms 2.12.9 |
| Automation | OttoKit 1.1.42 |
| MCP | mcp-adapter 0.7.1 + WP MCP Abilities 2.0.0 |
| Theme | OceanWP Child |
| Permalinks | plain (`?p=123`) — REST needs `index.php?rest_route=/...` |
| Database prefix | check `$wpdb->prefix`, never assume `wp_` |

Re-check with `wpmcp/wpcli`: `core version`, `plugin list --format=json`, `theme list --format=json`, `option get permalink_structure`.

## The operating loop

1. **Read before write.** Fetch the current value (post meta, option, file, setting) and keep a copy.
2. **Classify risk.** See the table below.
3. **Change the smallest surface.** One page, one option, one file — not a bulk sweep.
4. **Verify from the outside.** `wpmcp/fetch-page` or an HTTP fetch; not just "the write returned true".
5. **Purge caches.** `wp cache flush` + `wp litespeed-purge all` after any content change.
6. **Record the rollback.** Where is the backup, what is the exact undo command.

## Risk classes

| Class | Examples | Required before |
| --- | --- | --- |
| Read-only | list posts, get options, fetch HTML, audit | nothing |
| Low | edit a draft, add alt text, private note | nothing |
| Medium | publish content, change prices, activate a small plugin | know the undo |
| High | theme/plugin file edit, plugin update, permalink change, search-replace | DB export + file backup |
| Critical | core update, HPOS toggle, user roles, DNS/SSL, delete plugin with data | full backup + explicit human confirmation |

Never run a High or Critical operation as an unrequested side effect of a Medium task.

## Which skill for which task

**Core**
- Anything unfamiliar or multi-step → this playbook
- Shell/DB/plugin ops → `wp-cli-mastery`
- Custom endpoint or external integration → `rest-api-and-integrations`
- SQL, options, search-replace, cleanup → `database-operations`
- functions.php, mu-plugin, hooks, snippets → `hooks-and-custom-code`
- Posts, blocks, menus, media embeds → `content-and-gutenberg`
- theme.json, templates, patterns, FSE → `block-themes-and-theme-json`
- Child theme, template overrides, enqueue → `child-themes-and-templates`
- CPTs, taxonomies, roles, site structure → `site-architecture-and-cpts`

**Operations**
- Hardening, login, app passwords → `security-hardening`
- Hack, malware, defacement, spam → `incident-response-and-malware`
- Backup and restore → `backups-and-disaster-recovery`
- Move host, staging → live, URL change → `migration-staging-and-launch`
- White screen, 500, broken layout → `debugging-and-troubleshooting`
- Scheduled tasks, Action Scheduler → `cron-and-background-jobs`
- Order/customer email not arriving → `email-deliverability`

**Growth**
- Speed, LCP/INP/CLS → `performance-and-core-web-vitals`
- Page cache, object cache, CDN → `caching-and-cdn`
- Image import, alt, regenerate → `media-and-images`
- Titles, canonicals, sitemap, redirects → `technical-seo`
- Product/FAQ/breadcrumb schema → `structured-data-and-schema`
- GA4, GTM, consent, privacy → `analytics-consent-and-privacy`
- WCAG, keyboard, contrast → `accessibility-wcag`
- WPML/Polylang, hreflang → `multilingual-and-hreflang`
- Contact/quote forms, spam → `forms-and-lead-capture`
- Landing pages, funnel, A/B → `conversion-and-landing-pages`

**WooCommerce**
- Store settings, currency, pages → `woocommerce-store-setup`
- Products, variations, inventory → `woocommerce-products`
- Orders, statuses, refunds, fulfilment → `woocommerce-orders-and-fulfillment`
- Cart/checkout blocks, fields, fees → `woocommerce-checkout-and-cart`
- Gateways, shipping zones, tax → `woocommerce-payments-shipping-tax`
- Coupons, sale schedules, dynamic pricing → `woocommerce-coupons-and-pricing`
- Transactional emails, templates → `woocommerce-emails-and-notifications`
- Reports, revenue, cohorts → `woocommerce-analytics-and-reports`
- Slow store, cache exclusions, scale → `woocommerce-performance-and-scaling`
- Fraud, chargebacks, card testing → `woocommerce-fraud-and-disputes`
- CSV, feeds, Google Shopping → `woocommerce-import-export-and-feeds`

**Elementor**
- Page layout JSON edits → `elementor-editing`
- Global colors/fonts/kit → `elementor-kit`
- Header/footer/archive/single templates → `elementor-theme-builder`
- Dynamic tags, loop grid, ACF → `elementor-dynamic-content`
- Pro forms, webhooks, actions → `elementor-forms-and-actions`
- Elementor page weight → `elementor-performance`
- Editor blank, CSS stale, conflicts → `elementor-troubleshooting`
- Product/cart widgets, single product → `elementor-woocommerce`

## Non-negotiable rules

- **Backup before High/Critical.** `wp db export` plus a file archive; see `backups-and-disaster-recovery`.
- **Never edit core, parent theme, or a third-party plugin** for custom behaviour. Use a child theme or an mu-plugin.
- **Never write `post_status`, order status, or stock directly in SQL.** Use the API or CRUD so hooks, emails and stock run.
- **Never trust a write's return value alone.** Verify on the front end.
- **Never print credentials.** Application passwords live in config, not in chat, not in a skill.
- **Never run an unbounded query or a full search-replace** without `--dry-run` first.
- **Never assume HPOS vs posts storage.** Check `woocommerce_custom_orders_table_enabled` before touching order data.
- **Never assume block vs classic** for Elementor or cart/checkout. Detect first.

## Detection commands worth memorising

```
wp core version
wp plugin list --format=json --fields=name,status,version,update
wp option get woocommerce_custom_orders_table_enabled
wp option get permalink_structure
wp theme list --format=json
wp eval 'echo json_encode(get_option("elementor_active_kit"));'
wp db query "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=\"page\"" --format=json
```

## Verification checklist (front end)

- HTTP 200, not 301/302/503.
- Expected text/price present in the HTML.
- No `PHP Fatal` in `wp-content/debug.log` after the change.
- Cache header shows a fresh entry (`x-litespeed-cache: miss` right after purge).
- Console has no new JS errors on pages with forms, sliders or add-to-cart.

If any check fails, revert first, diagnose second.
