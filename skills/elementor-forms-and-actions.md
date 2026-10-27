---
title: Elementor Forms & Actions
category: Elementor
summary: Build and debug Elementor Pro forms — field schema, email actions, webhooks, redirects, spam protection, and where submissions actually go.
updated: 2026-10-26
---

# Elementor Forms & Actions

Elementor Pro forms (available here via PRO Elements) are widgets whose configuration lives in `form_fields` and `actions_after_submit`.

## Form structure

| Setting | Meaning |
| --- | --- |
| `form_name` | internal name |
| `form_fields` | array of field definitions |
| `email_to` | recipient(s), comma-separated |
| `email_subject` | subject, supports `[field id="name"]` |
| `email_content` | body template |
| `email_from`, `email_from_name` | sender |
| `email_reply_to` | usually the email field |
| `success_message` | shown after submit |
| `actions_after_submit` | `email`, `email2`, `redirect`, `webhook`, `mailchimp`, `discord`, `slack` |
| `redirect_to` | URL for the redirect action |
| `webhook_url` | endpoint for the webhook action |

Field definition:

```json
{
  "custom_id": "email",
  "field_type": "email",
  "field_label": "Email",
  "required": true,
  "width": "100",
  "placeholder": "you@example.com"
}
```

`custom_id` is what the email template and webhook payload use. Keep it short and stable.

## Email action

- `email_to` must be a monitored mailbox on your domain.
- `email_from` must be an authenticated domain address (see `email-deliverability`); a Gmail From fails DMARC.
- `email_reply_to` should be the submitter's email field ID.
- Test with a real submission; the editor preview does not send.

## Webhook action

Elementor posts JSON to `webhook_url` with the field values keyed by `custom_id`.

- Use HTTPS.
- Verify a shared secret/header on the receiving end when possible.
- Timeout is short; a slow endpoint causes a form error even though the email may have sent.
- Log failures on your side; Elementor does not retry.

## Redirect vs success message

- Redirect after submit for conversion tracking (`generate_lead` on the thank-you page).
- Success message for simple contact forms.
- Do not redirect to a page that is cached with personal data.

## Spam protection

- Elementor Pro has honeypot + reCAPTCHA v3.
- Prefer Cloudflare Turnstile if available (privacy + fewer false positives).
- Rate-limit at the server; form-level CAPTCHA alone is not enough.
- Block disposable email domains if lead quality matters.

## Where submissions are stored

By default: **nowhere**. Elementor emails the submission and discards it.

- Enable "Save submissions" only if you have a retention policy.
- Or use a CRM webhook.
- If you need an audit trail, store in a custom table or a form plugin, not in post meta.

## Accessibility

- Every field needs a label; placeholders are not labels.
- Mark required fields in text.
- Error messages must be announced (`aria-live`), not just colored.
- File upload fields need size/type limits and a clear label.

## Debugging a form that does not send

1. Submit on the front end (not preview).
2. Check the browser console/network for a 4xx/5xx.
3. Check `wp_mail` independently (see `email-deliverability`).
4. Check the spam folder.
5. Check the form's email settings and `email_to`.
6. Check a security plugin blocking `admin-ajax.php`.
7. Check the webhook endpoint logs if that action is used.

## Common configuration mistakes

| Mistake | Effect |
| --- | --- |
| `email_from` on a free mailbox | spam/rejection |
| `email_to` empty | no notification |
| `custom_id` duplicated | fields overwrite each other |
| Webhook URL HTTP | blocked or insecure |
| Redirect to a 404 | conversion lost |
| Required field hidden by conditional logic | form cannot submit |

## Conditional fields

Elementor Pro supports conditional display per field. Rules reference other fields' values.

- Hidden required fields still validate in some versions — test.
- Do not build a multi-step form with conditional logic alone; use a form plugin with real steps.

## Integration with SureForms

This site also has SureForms 2.12.9. Do not build the same form twice. Choose one:

- Elementor form when the design must be embedded in an Elementor layout.
- SureForms when you need entries, spam tooling and a simpler admin.

Mixing both for the same purpose fragments leads and analytics.

## Gotchas

- Deleting a form field breaks email templates referencing its `custom_id`.
- The `email2` action sends a second, separate email — often used for autoresponders.
- reCAPTCHA v3 scores are invisible; a "failed" submission may be a low score, not a bug.
- Cached pages can serve a stale nonce; exclude form pages from cache.
- Elementor forms do not work with the block checkout; they are independent.
