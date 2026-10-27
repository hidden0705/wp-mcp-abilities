---
title: Conversion & Landing Pages
category: Growth
summary: Build landing pages that load fast and convert — structure, proof, CTA hierarchy, Elementor implementation, and a measurement loop that does not lie.
updated: 2026-10-26
---

# Conversion & Landing Pages

## Page structure that works

1. **Hero**: outcome-led headline, one-line subhead, primary CTA, trust cue.
2. **Problem/agitation**: the specific pain, in the customer's words.
3. **Solution**: how the product removes it; 3 benefits, not 10 features.
4. **Proof**: reviews, photos, numbers, certifications, delivery/return policy.
5. **Objection handling**: price, sizing, shipping, returns, payment safety.
6. **Comparison** (optional): vs the obvious alternative.
7. **FAQ**: the last five objections.
8. **Final CTA**: repeat the primary action with a reason to act now.

One page, one goal. Every extra link is an exit.

## Above the fold

- Headline readable in 3 seconds on mobile.
- CTA visible without scrolling on a 390×844 viewport.
- Hero image under 200 KB, explicit dimensions.
- No carousel; it hides the message and hurts LCP.
- Price and shipping expectation near the CTA.

## CTA rules

- Verb + outcome: "Get the size guide", "Order the kurta", not "Submit".
- Contrast ≥ 4.5:1; 44×44 px minimum target.
- Repeat the CTA after each major section.
- Secondary CTA visually weaker (outline/text).
- Sticky mobile CTA for long pages.

## Elementor implementation

- Use a Container (not legacy section) for each band.
- Set the H1 once; section titles H2.
- Reuse global colors/fonts from the kit, not per-widget hex values.
- Build one mobile layout deliberately; check 390px, 768px, 1280px.
- Avoid more than 2 font families and 3 weights.
- Disable entrance animations on the hero.
- Use native form widgets or SureForms, not an image of a form.
- Add `id` anchors for in-page CTAs.

## Speed budget for a landing page

| Metric | Target |
| --- | --- |
| LCP | < 2.0 s mobile |
| INP | < 200 ms |
| CLS | < 0.05 |
| Page weight | < 1.2 MB |
| Requests | < 60 |

A landing page with a 3 MB hero and four tracking pixels will not convert on mobile data.

## Proof that actually persuades

- Named reviews with a photo or order verification.
- Specific numbers ("12,400 orders shipped", not "trusted by thousands").
- Real product photos, not only studio renders.
- Delivery time and return window stated plainly.
- Payment logos only if those methods are actually enabled.

## Testing

- Test one variable at a time: headline, hero, CTA copy, price framing, proof placement.
- Need ~100 conversions per variant for a meaningful read; below that, use qualitative feedback.
- Run for at least one full business cycle (7–14 days).
- Do not test during a sale or a broken checkout.
- Tools: Google Optimize is gone; use the host's A/B tool, Clarity/Microsoft Clarity heatmaps, or a simple two-URL split with GA4.

## Measurement

Events to track:

| Event | When |
| --- | --- |
| `view_item_list` | category/landing product grid |
| `select_item` | product click |
| `add_to_cart` | add to cart |
| `begin_checkout` | checkout view |
| `purchase` | order received (dedupe by transaction_id) |
| `generate_lead` | form submit |

Verify with GA4 DebugView, not by trusting the plugin's dashboard.

## Common conversion killers

- Shipping cost revealed only at checkout.
- Forced account creation.
- Form with 9 fields.
- Popup within 2 seconds of load.
- Slow LCP image.
- No size guide on apparel.
- Payment failure with a generic error.
- Mobile menu that traps scroll.

## Audit loop

1. Fetch the page, check 200 + content.
2. `wpmcp/audit-url` mobile.
3. Tab through with keyboard; submit the form.
4. Add to cart → checkout on mobile.
5. Check GA4 events.
6. Compare against the previous 14 days.

## Gotchas

- Elementor popups can fire before consent and before the page is interactive; delay them.
- A/B tests on cached pages serve the same variant; exclude test URLs from cache or use edge splitting.
- Counting "add to cart" as a conversion inflates results; measure paid orders.
- Discount-led pages attract one-time buyers; track repeat rate, not just conversion.
- Landing pages that duplicate an existing product page cannibalise its rankings; canonicalise or noindex the variant.
