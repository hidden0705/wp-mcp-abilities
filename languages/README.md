# Translations

The plugin loads its text domain from this directory:

```php
load_plugin_textdomain( 'wp-mcp-abilities', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
```

Text domain: `wp-mcp-abilities`.

## Current state

Most strings in this plugin are written for an AI agent, not for a human reading a
settings page: tool descriptions, error payloads and skill playbooks are the
interface. Those stay in English on purpose, because the client sends them back to
the model verbatim.

Human-facing strings — anything that ends up in wp-admin — use the
`wp-mcp-abilities` text domain and can be translated here.

## Generating a POT

With WP-CLI's i18n command:

```bash
wp i18n make-pot . languages/wp-mcp-abilities.pot --domain=wp-mcp-abilities \
  --exclude=vendor,node_modules,.git
```

## Adding a translation

1. Create `wp-mcp-abilities-<locale>.po` (for example `wp-mcp-abilities-de_DE.po`).
2. Compile it to `.mo` with `msgfmt` or Poedit.
3. Drop both files in this directory. WordPress picks them up automatically.

Language packs are not distributed with GitHub releases; ship them as a pull request
or keep them in your own `wp-content/languages/plugins/` directory.
