---
title: REST API & Integrations
category: Core
summary: Use the WordPress and WooCommerce REST APIs correctly — auth, routes, pagination, batch, error shapes, and how to build a custom endpoint without breaking the site.
updated: 2026-10-26
---

# REST API & Integrations

Use `wpmcp/rest-request` for any `/wp-json` route. It authenticates as the administrator server-side, so no consumer key is needed.

## Discovering routes

```
GET /
GET /wp/v2
GET /wc/v3
```

The index lists namespaces and routes. On plain permalinks the external URL is `https://site/index.php?rest_route=/wp/v2/posts`, but inside this ability always pass `/wp/v2/...`.

## Auth models

| Model | Use |
| --- | --- |
| Application Password (Basic) | agents, scripts, this MCP server |
| Cookie + nonce | browser/admin JS |
| Consumer key/secret | external apps over HTTPS, WooCommerce legacy |
| JWT plugin | not present; avoid adding unless required |

Application passwords require HTTPS and can be revoked per integration.

## Request shape

```
{ "method": "POST", "route": "/wp/v2/posts", "body": { "title": "Hello", "status": "draft" } }
```

Query parameters can be inline: `/wc/v3/orders?status=processing&per_page=20`.

## Response handling

A successful call returns `{status: 200, body: ...}`. Errors come back with a 4xx/5xx status and a body like:

```
{ "code": "rest_invalid_param", "message": "Invalid parameter(s): status", "data": { "status": 400 } }
```

Read the `code`, not just the status: `rest_forbidden` (capability), `rest_no_route` (route/namespace missing), `rest_invalid_param` (schema), `woocommerce_rest_*` (store-level rules).

## Pagination

- `per_page` max 100 (WooCommerce and core posts).
- Use `page=2,3,4`; stop when the returned array is shorter than `per_page` or empty.
- Read `X-WP-Total` / `X-WP-TotalPages` headers if you need totals — the ability returns only the body, so compute by paging or query the DB for a count.

## Core routes worth knowing

| Route | Use |
| --- | --- |
| `/wp/v2/posts`, `/pages` | content CRUD |
| `/wp/v2/media` | attachments |
| `/wp/v2/users`, `/users/me` | accounts |
| `/wp/v2/plugins` | install/activate/update |
| `/wp/v2/themes` | list/activate |
| `/wp/v2/settings` | site settings |
| `/wp/v2/categories`, `/tags` | taxonomy |
| `/wp/v2/comments` | moderation |
| `/wp/v2/search` | cross-type search |
| `/wp/v2/block-types`, `/blocks` | block metadata |
| `/wp-site-health/v1` | diagnostics |
| `/wp/v2/abilities` (WP 7.x) | abilities discovery |

## WooCommerce routes

| Route | Use |
| --- | --- |
| `/wc/v3/products` | products, variations |
| `/wc/v3/orders` | orders, notes, refunds |
| `/wc/v3/customers` | customer records |
| `/wc/v3/coupons` | coupons |
| `/wc/v3/reports/*` | sales, totals, top sellers |
| `/wc/v3/settings/*` | store settings groups |
| `/wc/v3/system_status` | environment report |
| `/wc/v3/payment_gateways` | enable/configure gateways |
| `/wc/v3/shipping/zones` | zones, methods, locations |
| `/wc/v3/taxes` | rates and classes |
| `/wc/v3/products/batch`, `/orders/batch` | up to 100 ops |

## Installing a plugin over REST

```
POST /wp/v2/plugins { "slug": "litespeed-cache", "status": "active" }
PUT  /wp/v2/plugins/litespeed-cache/litespeed-cache.php
```

The identifier is `slug/file.php`, not the display name. List first and read `plugin`.

## Writing a custom endpoint

Put it in an mu-plugin, not a theme:

```php
add_action( 'rest_api_init', function () {
    register_rest_route( 'qadri/v1', '/quote', array(
        'methods'             => 'POST',
        'callback'            => function ( WP_REST_Request $r ) {
            $name = sanitize_text_field( (string) $r->get_param( 'name' ) );
            if ( '' === $name ) {
                return new WP_Error( 'qadri_missing_name', 'Name is required.', array( 'status' => 400 ) );
            }
            return rest_ensure_response( array( 'ok' => true, 'name' => $name ) );
        },
        'permission_callback' => function () {
            return current_user_can( 'edit_posts' );
        },
        'args'                => array(
            'name' => array( 'required' => true, 'type' => 'string' ),
        ),
    ) );
} );
```

Rules: always a `permission_callback`; always sanitize; return `WP_Error` with a status; never echo; never `die()`.

## Webhooks and outbound calls

- WooCommerce webhooks: `POST /wc/v3/webhooks` with `topic`, `delivery_url`, `secret`.
- Verify inbound webhooks by HMAC (`base64_encode( hash_hmac( 'sha256', $body, $secret, true ) )`) against the `X-WC-Webhook-Signature` header.
- Use `wp_remote_post` with a timeout, and log failures; never block a checkout on a third-party call.

## Gotchas

- Cloudflare/WAF can block REST with a 403 HTML page; check `content-type`.
- Security plugins sometimes require a nonce even for app-password auth.
- `rest_no_route` on a working site usually means a plugin that registers routes is inactive, or permalinks changed.
- Deleting via REST trashes by default; `?force=true` is permanent.
- Batch responses can mix success and failure per item — check every element, not just HTTP 200.
- WooCommerce 11.3+ moves MCP integration to the WordPress MCP Adapter; do not install a separate Woo MCP endpoint.
