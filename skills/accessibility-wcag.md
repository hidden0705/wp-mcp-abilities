---
title: Accessibility (WCAG)
category: Growth
summary: Audit and fix the accessibility issues that matter most — contrast, keyboard access, focus, forms, alt text, headings, and Elementor/WooCommerce specifics.
updated: 2026-10-26
---

# Accessibility (WCAG)

Target WCAG 2.2 AA. Accessibility is also SEO, usability and legal risk reduction.

## Fast audit (no special tools)

1. **Keyboard only**: Tab through the homepage, menu, product page, cart, checkout. Every interactive element must be reachable and visible when focused.
2. **Zoom 200%**: no content clipped or overlapping.
3. **Contrast**: text vs background ≥ 4.5:1 (normal), 3:1 (large ≥ 24px or 19px bold).
4. **Forms**: every input has a visible label; errors are announced, not just red.
5. **Images**: informative images have alt; decorative ones have empty alt.
6. **Headings**: one H1, no skipped levels.
7. **Motion**: no autoplay carousel without pause; respect `prefers-reduced-motion`.

Automated tools catch ~30–40%: axe DevTools, Lighthouse accessibility, WAVE. Manual keyboard and screen-reader checks catch the rest.

## Contrast quick reference

| Foreground | Background | Ratio | Use |
| --- | --- | --- | --- |
| #111827 | #FFFFFF | 16.1 | body text |
| #6B7280 | #FFFFFF | 4.8 | secondary text (passes) |
| #9CA3AF | #FFFFFF | 2.5 | fails normal text |
| #FFFFFF | #E11D48 | 4.6 | button text (just passes) |
| #FFFFFF | #F59E0B | 2.1 | fails |

Check every button, badge, sale price, footer link and placeholder.

## Elementor specifics

- Use real heading widgets with the correct `header_size` (H1 for the page title, H2 for sections). A styled div is not a heading.
- Icon boxes and buttons: Elementor buttons render `<a>` with role; ensure the link text is meaningful ("Shop kurtas", not "Click here").
- Tabs, accordions, carousels: verify keyboard operation and `aria-expanded`.
- Popups: focus must move into the popup and return on close; Elementor handles some of this, test it.
- Animations: disable entrance animations for users with reduced motion where possible.
- Forms: Elementor Pro form fields have labels; hidden labels need `aria-label` or a visually-hidden label.

## WooCommerce specifics

- Product images need alt text (attachment alt, not the product title by default).
- Variable product dropdowns need labels; some themes render them unlabeled.
- "Add to cart" ajax feedback must be announced (`aria-live`), not just visual.
- Checkout block: labels are generally good; custom fields added by plugins often are not.
- Star ratings need text equivalents ("4.5 out of 5").
- Sale badges must not rely on colour alone.

## Fix patterns

**Visually hidden text**

```css
.screen-reader-text {
  border: 0; clip: rect(1px,1px,1px,1px); clip-path: inset(50%);
  height: 1px; width: 1px; overflow: hidden; position: absolute !important;
  white-space: nowrap;
}
```

**Visible focus**

```css
a:focus-visible, button:focus-visible, input:focus-visible {
  outline: 3px solid #0F172A;
  outline-offset: 2px;
}
```

Never `outline: none` without a replacement.

**Skip link**

```html
<a class="skip-link screen-reader-text" href="#content">Skip to content</a>
```

**Reduced motion**

```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; }
}
```

## Forms

- Label tied to input (`for`/`id`), not placeholder-only.
- Required fields marked in text, not just `*` with colour.
- Errors: text message + `aria-describedby`, focus moves to the first error.
- Autocomplete attributes (`email`, `tel`, `shipping address-line1`) help everyone.
- CAPTCHA must have an accessible alternative; invisible CAPTCHA preferred.

## Tables and data

- Header cells `<th scope="col">`.
- Caption or preceding heading describing the table.
- Avoid layout tables.

## Media

- Video: captions and a transcript.
- Audio: transcript.
- No flashing content (>3 flashes/second).

## Testing with a screen reader

- NVDA (Windows, free), VoiceOver (macOS/iOS), TalkBack (Android).
- Tab through checkout with NVDA on: every field must announce its label and state.

## Accessibility statement

Publish a page stating conformance target, known issues, and a contact route. It is required in some jurisdictions and expected in procurement.

## Gotchas

- Contrast tools disagree on gradients and images behind text; measure the worst pixel.
- `aria-hidden="true"` on a focusable element traps keyboard users.
- Placeholder text is not a label.
- Icon-only buttons need `aria-label`.
- Elementor's "improved asset loading" does not affect semantics; the widget choice does.
- Fixing accessibility can change layout; verify visually after CSS changes.
