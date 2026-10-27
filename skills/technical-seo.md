---
title: Technical SEO
category: Growth
summary: Titles, meta, canonicals, indexation, sitemaps, redirects and internal linking — the technical layer an agent can verify from the rendered HTML.
updated: 2026-10-26
---

# Technical SEO

## Detect the SEO stack

```
wp plugin list --status=active --fields=name,version --format=json
```

| Plugin | Title key | Description key | Noindex |
| --- | --- | --- | --- |
| Yoast | `_yoast_wpseo_title` | `_yoast_wpseo_metadesc` | `_yoast_wpseo_meta-robots-noindex` |
| Rank Math | `rank_math_title` | `rank_math_description` | `rank_math_robots` (array) |
| AIOSEO | `_aioseo_title` | `_aioseo_description` | `_aioseo_robots_noindex` |
| SEOPress | `_seopress_titles_title` | `_seopress_titles_desc` | `_seopress_robots_index` |

Never write Yoast keys on a Rank Math site.

## Titles and descriptions

```
wp eval '
update_post_meta( 123, "_yoast_wpseo_title", "Cotton Kurta for Men | Example Store" );
update_post_meta( 123, "_yoast_wpseo_metadesc", "Premium cotton kurtas for men. Free shipping over $99. Sizes S-XXL." );
echo "ok";
'
```

- Title 50–60 chars; primary keyword early; brand last.
- Description 140–160 chars; a reason to click, not a keyword list.
- One search intent per page.
- Bulk-editing every product title looks like spam; change the template instead.

## Permalinks and URLs

```
wp option get permalink_structure
```

Empty = plain (`?p=123`), poor for SEO and REST. Changing it on a live site requires 301s from every old URL — plan first.

Preferred: `/%postname%/`, products `/product/%product_cat%/` only if category structure is stable.

## Canonicals

Every indexable page needs one canonical. Common duplicates:

- `?orderby=`, `?filter_`, `?add-to-cart=`
- tracking params (`utm_*`, `fbclid`)
- paginated archives (self-canonical, not page 1)
- `http` vs `https`, `www` vs non-www
- trailing slash variants

Verify:

```
wpmcp/fetch-page { "url": "https://example.com/product/cotton-kurta/" }
```

Grep the HTML for `rel="canonical"`. It must be absolute, https, and match the preferred host.

## Indexation

```
wp option get blog_public
```

`0` = "discourage search engines" — a staging leftover that kills rankings. `1` = indexable.

Check `robots.txt`:

```
wpmcp/fetch-page { "url": "https://example.com/robots.txt" }
```

It must not `Disallow: /`, and it should reference the sitemap.

Noindex a page (Yoast example):

```
wp post meta update 123 _yoast_wpseo_meta-robots-noindex 1
```

Use for thin tag archives, internal search, staging.

## Sitemaps

- Core: `/wp-sitemap.xml`
- Yoast/Rank Math: `/sitemap_index.xml`

Verify products and pages appear and no drafts/private URLs leak. Regenerate after bulk imports by purging caches; some plugins rebuild on request.

## Redirects

At the server (best for performance) or via the SEO plugin's redirect manager:

```
Redirect 301 /old-product/ /product/new-product/
```

Rules:
- 301 for permanent, 302 only for temporary campaigns.
- One hop only; no chains.
- Preserve query strings where meaningful.
- Redirect deleted products to the category, not the homepage, when possible.
- Never redirect everything to home — search engines treat it as a soft 404.

Bulk check for 404s after a migration: crawl the old sitemap URLs and log status codes.

## Internal linking

- Every product links to its category and 2–3 related products.
- Blog posts link to the relevant product/category with descriptive anchors.
- Orphan pages: find posts with zero internal links and add them to navigation or a hub page.
- Breadcrumbs help both users and crawlers; WooCommerce + SEO plugin usually provide them.

## International

See `multilingual-and-hreflang`. A single-language store should not emit `hreflang` at all.

## Structured data

See `structured-data-and-schema`. Product snippets need price, availability, SKU, brand, image.

## Content quality signals

- One `<h1>` per page; Elementor widgets set their own HTML tag — check `header_size`.
- Descriptive alt text on images (see `media-and-images`).
- No keyword stuffing; no auto-generated doorway pages.
- Update dates only when content actually changes.

## Verification checklist

1. `wpmcp/fetch-page` returns 200.
2. `<title>` is unique and correct length.
3. Meta description present.
4. Canonical absolute and correct.
5. No `noindex` on a page that should rank.
6. Schema present and valid (Rich Results Test).
7. Sitemap includes the URL.
8. No redirect chain to reach it.
9. Mobile rendering OK.
10. LCP/INP within thresholds (see `performance-and-core-web-vitals`).

## Gotchas

- Elementor's own `<title>` handling can conflict with the SEO plugin; one source of truth.
- `blog_public=0` is easy to leave behind after staging.
- Changing a slug without a redirect loses accumulated links.
- Tag archives with one post each create thin duplicates; noindex or disable them.
- Google takes weeks to reflect changes; do not re-edit daily.
