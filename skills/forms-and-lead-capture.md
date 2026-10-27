---
title: Forms & Lead Capture
category: Growth
summary: Build contact, quote and newsletter forms that deliver — field design, spam protection, deliverability, storage, and what to check when submissions vanish.
updated: 2026-10-26
---

# Forms & Lead Capture

This site has **SureForms 2.12.9** active. Use it unless a specific integration requires otherwise.

## Before building

- Where should the submission go? Email, CRM, webhook, database?
- Who answers it, and within what time?
- What is the minimum data needed? Every extra field lowers completion.
- Privacy notice and consent checkbox if you store personal data.

## Field design

| Field | Rule |
| --- | --- |
| Name | one field or first/last; not both plus full name |
| Email | `type=email`, autocomplete |
| Phone | optional unless you call leads; `type=tel` |
| Message | optional for quote forms, required for support |
| Consent | unchecked by default, linked to privacy policy |
| Honeypot | hidden, not `display:none` only |

Do not ask for company size, budget and address on a first-touch form.

## SureForms essentials

- Create form → add fields → set notifications.
- Notification email must come from the site domain (see `email-deliverability`); a `gmail.com` From address fails DMARC.
- Enable spam protection (honeypot + reCAPTCHA/Turnstile).
- Store entries in the database if you need an audit trail; otherwise email-only is fewer data to protect.
- Add the form with the block or shortcode; test the front end, not the preview.

## Spam protection layers

1. Honeypot field.
2. Time trap (reject submissions under 2–3 seconds).
3. Cloudflare Turnstile (preferred over reCAPTCHA v3 for privacy).
4. Rate limiting per IP at the server.
5. Content heuristics (link count, keywords) as a last resort.

Do not rely on a single CAPTCHA; determined spam bots solve them.

## Delivery checklist

- [ ] Test submission arrives in the inbox
- [ ] Reply-To is the submitter's email
- [ ] From domain is authenticated (SPF/DKIM)
- [ ] Autoresponder arrives (if enabled)
- [ ] Entry stored (if enabled)
- [ ] GDPR consent recorded with timestamp
- [ ] Error states visible and accessible
- [ ] Success message does not reload to an empty page
- [ ] Works with cache enabled (form page excluded or nonce handled)

## When submissions vanish

1. Check the form plugin's entries/log first — did it receive anything?
2. Check SMTP provider logs for the notification.
3. Send a test with `wp_mail` to isolate WordPress vs the form.
4. Check spam folder and the recipient's filtering.
5. Check for a JS error blocking submit.
6. Check a security plugin blocking `admin-ajax.php` or the REST route.
7. Check rate limiting/WAF.

## Newsletter capture

- Use a dedicated provider (Mailchimp, Brevo, MailerLite) with double opt-in.
- Do not store newsletter emails only in WordPress.
- Separate transactional and marketing sending (see `email-deliverability`).
- Add consent text and a link to the privacy policy.

## Webhooks / CRM

```
POST https://crm.example.com/hook
Content-Type: application/json
X-Signature: HMAC-SHA256(body, secret)
```

- Verify the HMAC on the receiving side.
- Timeout 5–10s; never block the form submit on a slow CRM.
- Log failures with the submission ID, not the full personal data.
- Retry with backoff, and cap retries.

## Anti-patterns

- Requiring an account to contact you.
- CAPTCHA on every page load (performance + accessibility).
- Sending form notifications to a personal Gmail.
- Storing credit card data in a form.
- Auto-subscribing every contact to marketing without consent.
- Hiding the privacy policy in the footer only.

## Gotchas

- Cached form pages can serve a stale nonce → "invalid submission". Exclude form pages from cache or configure the plugin.
- A `From` address on a different domain than the authenticated sender gets rejected.
- File uploads in forms need size/type validation and storage outside the web root when sensitive.
- Multiple forms with the same field names can collide in the database.
- Deactivating a form plugin leaves shortcodes visible on the page.
