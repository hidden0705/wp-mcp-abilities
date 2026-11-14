---
title: WordPress Expert Playbook
category: Core
summary: Entry point and decision tree for running this site like a senior WordPress operator — pre-flight, risk classes, verification loop, and which of the skill library to load next.
updated: 2026-10-26
---

# WordPress Expert Playbook

Load this first. It tells you how to work safely on this site, how to verify, and which playbook covers the task.

## Site profile (verify, do not assume forever)

| Item | Fill in at intake |
| --- | --- |
| WordPress | core version |
| WooCommerce | version, HPOS on/off, cart/checkout type |
| Elementor | version, atomic or classic |
| Cache | page cache plugin, object cache, CDN |
| Forms | plugin and version |
| Automation | plugin and version |
| MCP | adapter version and abilities version |
| Theme | parent and child theme |
| Permalinks | pretty or plain; plain needs `index.php?rest_route=/...` |
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

This list covers the whole library. A stock install ships the six starter playbooks;
the rest are the Pro pack. If `get-skill` returns not found, that pack is not
installed — continue with the core abilities and the starter playbooks.


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
