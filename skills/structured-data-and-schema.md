---
title: Structured Data & Schema
category: Growth
summary: Add valid Product, Offer, Breadcrumb, FAQ and Organization schema — what Google requires, how WooCommerce/SEO plugins emit it, and how to verify without guesswork.
updated: 2026-10-26
---

# Structured Data & Schema

Schema helps rich results and machine understanding. Invalid or conflicting schema is worse than none.

## Where it comes from

| Source | Emits |
| --- | --- |
| WooCommerce core | Product, Offer, AggregateRating (some), BreadcrumbList |
| Yoast WooCommerce SEO | enhanced Product, Offer, Brand, GTIN |
| Rank Math | Product, FAQ, HowTo, LocalBusiness modules |
| Elementor | rarely schema; sometimes duplicate FAQ markup |
| Theme | Organization/WebSite in some themes |

Multiple plugins emitting `Product` for the same URL is the most common defect.

## Verify what is live

```
wpmcp/fetch-page { "url": "https://qadrimarket.com/product/cotton-kurta/" }
```

Search the HTML for `application/ld+json`. Extract and validate each block. Tools: Google Rich Results Test, Schema Markup Validator.

Check for duplicates:

```
wp eval '
$html = file_get_contents( "https://qadrimarket.com/product/cotton-kurta/" );
preg_match_all( "/<script type=\"application\/ld\+json\">(.*?)<\/script>/s", $html, $m );
echo count( $m[1] ) . " blocks\n";
foreach ( $m[1] as $json ) { $d = json_decode( $json, true ); echo ( $d["@type"] ?? "?" ) . "\n"; }
'
```

(`file_get_contents` on a URL requires `allow_url_fopen`; use `wpmcp/fetch-page` if disabled.)

## Minimum valid Product

```json
{
  "@context": "https://schema.org",
  "@type": "Product",
  "name": "Cotton Kurta - Navy",
  "image": ["https://qadrimarket.com/img/kurta.jpg"],
  "description": "Handloom cotton kurta.",
  "sku": "QMK-NAVY-M",
  "brand": { "@type": "Brand", "name": "Qadri Market" },
  "offers": {
    "@type": "Offer",
    "url": "https://qadrimarket.com/product/cotton-kurta/",
    "priceCurrency": "INR",
    "price": "1499.00",
    "availability": "https://schema.org/InStock",
    "itemCondition": "https://schema.org/NewCondition"
  }
}
```

Rules:
- `price` is a string with two decimals, currency in ISO 4217.
- `availability` must be a schema URL, not "in stock".
- `image` should be ≥ 1200px for large previews.
- Variable products: one `Product` with `offers` array or `AggregateOffer` (lowPrice/highPrice/offerCount).
- Do not mark up prices that differ from the visible page — a manual action risk.

## Breadcrumbs

```json
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    { "@type": "ListItem", "position": 1, "name": "Home", "item": "https://qadrimarket.com/" },
    { "@type": "ListItem", "position": 2, "name": "Kurtas", "item": "https://qadrimarket.com/product-category/kurtas/" },
    { "@type": "ListItem", "position": 3, "name": "Cotton Kurta" }
  ]
}
```

The visible breadcrumb must match. The last item has no `item` (current page).

## FAQ (eligible only in limited cases)

```json
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    { "@type": "Question", "name": "What sizes are available?",
      "acceptedAnswer": { "@type": "Answer", "text": "S to XXL." } }
  ]
}
```

Google reduced FAQ rich results for most sites; still useful for machine parsing, but do not promise visual rich results.

## Organization / WebSite

Put one Organization block site-wide (logo, sameAs social profiles, contactPoint). `WebSite` with `potentialAction: SearchAction` only if the site has a real search endpoint.

## Adding schema without a plugin

In an mu-plugin, on `wp_head`:

```php
add_action( 'wp_head', function () {
    if ( ! is_product() ) { return; }
    $product = wc_get_product( get_the_ID() );
    if ( ! $product ) { return; }
    $data = array(
        '@context' => 'https://schema.org',
        '@type'    => 'Product',
        'name'     => $product->get_name(),
        'sku'      => $product->get_sku(),
        'image'    => wp_get_attachment_url( $product->get_image_id() ),
        'offers'   => array(
            '@type'         => 'Offer',
            'price'         => $product->get_price(),
            'priceCurrency' => get_woocommerce_currency(),
            'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'url'           => get_permalink( $product->get_id() ),
        ),
    );
    echo '<script type="application/ld+json">' . wp_json_encode( $data ) . '</script>';
}, 20 );
```

Do not add this if WooCommerce/SEO already emits Product — you will create duplicates.

## Common failures

| Failure | Cause |
| --- | --- |
| "Missing field offers" | variable product with no variation prices |
| "Price mismatch" | sale price cached, schema stale |
| Duplicate Product | theme + plugin both emit |
| Invalid availability | "in stock" instead of schema URL |
| Missing image | product has no featured image |
| Breadcrumb mismatch | visible trail differs from JSON |
| GTIN invalid | placeholder 12345 in the field |

## Testing loop

1. Fetch the page HTML.
2. Extract every `ld+json` block.
3. `json_decode` each; a parse error invalidates the block.
4. Check required fields per type.
5. Compare price/availability with the visible page.
6. Validate in Rich Results Test.
7. Re-check after any theme, plugin or SEO setting change.

## Gotchas

- Cache can serve old schema after a price change; purge before validating.
- `wp_json_encode` escapes slashes by default; either is valid JSON, but keep one style.
- Schema in the `<body>` via a page builder is allowed but easy to duplicate; prefer `wp_head`.
- Do not mark up reviews you do not have; fabricated ratings are a manual-action risk.
- Currency must match the store currency; a multi-currency plugin can desync it.
