# Greyhound Performance

WordPress plugin from named for the **greyhound**, the classic track racing dog: lean, fast, and stripped of extra weight.

It trims unnecessary front-end and head output, reduces emoji-related assets, and tightens the XML-RPC / pingback surface area.

## Installation

1. Copy the `sfas-performance` folder into `wp-content/plugins/`.
2. Activate **Greyhound Performance** under **Plugins** in the admin.

With [Lando](https://lando.dev/):

```bash
lando wp plugin activate sfas-performance
```

## What it does

| Area | Change |
|------|--------|
| `<head>` | Removes RSD, Windows Live Writer manifest, version generator, shortlink, relational “prev/next” links (including `adjacent_posts_rel_link_wp_head` where applicable). |
| Feeds | Removes default `feed_links` on `wp_head` (priority 2). **Category/author/tag feed `<link>` tags will not appear** unless you disable this (see filters). |
| jQuery | On the **front end only**, drops the `jquery-migrate` dependency when core jQuery is enqueued. |
| oEmbed | Removes oEmbed discovery links, host JS hook, REST oEmbed route registration, and the `oembed_dataparse` filter callback. |
| Pingbacks | Strips internal self-links before pings, removes `X-Pingback`, clears pingback URL from `bloginfo` / `bloginfo_url`, removes `pingback.ping` from XML-RPC methods. |
| XML-RPC | By default, disables XML-RPC entirely (`xmlrpc_enabled` → false). **This breaks anything that relies on XML-RPC** (some mobile apps, older integrations). |
| Emoji | Removes front-end and admin emoji scripts/styles, feed/email emoji filters, TinyMCE `wpemoji` plugin, and emoji SVG from DNS-prefetch hints. |

## Security-focused choices

- **XML-RPC off by default** shrinks a common brute-force and exploit target. Re-enable only if you need it.
- **Pingback / header cleanup** reduces noisy metadata and a small bit of attack surface.
- **No regex rewriting of `<link rel="stylesheet">` tags** — the old pattern was fragile (RTL, `id` attributes, HTML shape changes) and could break themes or caching. This plugin does not ship that optimization.

## Performance notes

- Fewer scripts and styles in `wp_head` and on init.
- Fewer DNS-prefetch hints.
- Smaller jQuery payload on the front end when migrate is not required.

Always measure with your real theme and plugins; removing feeds or oEmbed can affect SEO tools, readers, or embed UX.

## Filters (developer)

Add these in a small custom plugin or the theme’s `functions.php`:

```php
// Keep RSS/Atom discovery links in <head>.
add_filter( 'greyhound_perf_remove_feed_head_links', '__return_false' );

// Allow XML-RPC (mobile apps, Jetpack-class features, etc.).
add_filter( 'greyhound_perf_disable_xmlrpc', '__return_false' );
```

## Optional: classic editor

The main plugin file includes a commented example:

```php
add_filter( 'use_block_editor_for_post', '__return_false' );
```

Uncomment only if you intentionally want the block editor off site-wide (prefer a dedicated “classic editor” plugin for UI and updates).

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Changelog

### 1.0.0

- Initial release: head cleanup, feed link removal (filterable), jQuery migrate removal on front end, oEmbed hook removal, pingback/XML-RPC hardening, emoji disable.

## License

GPL-2.0-or-later
