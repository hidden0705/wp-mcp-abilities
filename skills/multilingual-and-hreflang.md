---
title: Multilingual & hreflang
category: Growth
summary: Add a second language without duplicate-content damage — WPML/Polylang setup, per-language URLs, hreflang, translated slugs, and WooCommerce pitfalls.
updated: 2026-10-26
---

# Multilingual & hreflang

## Choose the model first

| Model | Use when |
| --- | --- |
| Subdirectory (`/hi/`, `/en/`) | single domain, easiest SEO |
| Subdomain (`hi.example.com`) | separate hosting/CDN per language |
| Separate domains | distinct brands/countries |
| Query param (`?lang=hi`) | avoid; weak SEO signal |

Subdirectory is the default recommendation.

## Plugin choice

- **Polylang** — lightweight, free core, WooCommerce support in Pro.
- **WPML** — full WooCommerce/multicurrency, paid, heavier.
- **TranslatePress** — visual translation, good for small sites.
- **Multisite + per-site language** — heavy; only for genuinely separate stores.

Do not run two multilingual plugins. Do not machine-translate checkout and legal pages without review.

## What must be translated

- Pages, posts, products, categories, attributes, tags
- Menus and widgets
- Theme/plugin strings (`.po`/`.mo` or the plugin's string translation)
- SEO titles/descriptions per language
- Emails (WooCommerce emails per language)
- Checkout, cart, account, legal pages
- Slugs (preferred) and image alt text

## hreflang

Every page in a language set needs reciprocal links:

```html
<link rel="alternate" hreflang="en" href="https://example.com/product/cotton-kurta/" />
<link rel="alternate" hreflang="hi" href="https://example.com/hi/product/cotton-kurta/" />
<link rel="alternate" hreflang="x-default" href="https://example.com/product/cotton-kurta/" />
```

Rules:
- Reciprocal: A links to B, B links to A.
- Absolute URLs, including protocol and host.
- `x-default` for the language selector/global fallback.
- Use `hi-IN` for regional variants, `hi` for generic.
- Do not hreflang pages that are not translations of each other.
- Self-referencing hreflang is correct and expected.

Most plugins add these automatically; verify in the rendered HTML rather than trusting the setting.

## Verify

```
wpmcp/fetch-page { "url": "https://example.com/hi/" }
```

Grep for `hreflang`. Then check the English page links back. A one-way set is ignored.

## Duplicate content

- Each translation should be a distinct URL with its own title/description.
- Do not leave the default-language copy indexable at a second URL.
- Category archives in two languages need their own canonicals.
- Machine-translated product descriptions duplicated across languages can be treated as thin; review the top sellers.

## WooCommerce specifics

- Products: translate title, description, short description, attributes, category, tags, alt text.
- Variations: translated attribute values must map to the same variation IDs.
- Checkout: country list, address formats, currency.
- Emails: per-language templates; a customer who ordered in Hindi should not get an English email.
- Coupons and shipping zones can be language-independent; do not duplicate them per language.
- HPOS orders store the locale used at checkout; reports should not split by language.

## URL and slug strategy

Preferred: translated slugs (`/hi/product/cotton-kurta/` → `/hi/product/suti-kurta/`) with a 301 from the auto-generated slug if it changes.

Changing a slug after launch requires redirects; do it before content is indexed.

## Language switcher

- Visible in header, not buried in the footer.
- Uses `<a hreflang>` links, not JavaScript-only toggles.
- Preserves the current page when switching, not just the homepage.
- Does not set a cookie that hides content from crawlers.

## Gotchas

- Geo-IP redirects can prevent crawlers from seeing all languages; offer a banner instead of an automatic redirect.
- A default language with no prefix and a second language with a prefix is fine; be consistent.
- hreflang in the sitemap and in the HTML can conflict; pick one source.
- Translating only the homepage creates orphan pages; translate the navigation path too.
- Elementor templates are per-language in WPML/Polylang; a translated page may fall back to the default template.
- Currency conversion is not translation; do not mix them in one plugin setting.
