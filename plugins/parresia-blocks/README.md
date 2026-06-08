# Parresia Blocks

WordPress plugin for integrating Parresia editorial template blocks into Gutenberg and classic sidebars.

## Included

- One dynamic Gutenberg block: `Parresia block`
- Variants: hero, feature, article card, video card, masterclass, magazine, subscription, rail list, agenda, poll, expo logo strip, ad
- Automatic content queries by post type, category, tag, custom taxonomy slug, quantity, date or views
- Gutenberg patterns for homepage and sidebar compositions
- Classic sidebar widget: `Parresia Block`
- Shortcode: `[parresia_block]`
- Advanced Ads support through the official shortcodes:
  - `[the_ad id="123"]`
  - `[the_ad_placement id="top-banner"]`
  - custom shortcode mode

## Examples

```text
[parresia_block type="video-card" title="Chirurgie implantaire guidee" kicker="Videos" show_views="true" views="2400" theme="dark"]
```

```text
[parresia_block type="slider" source="query" post_type="post" taxonomy="category" terms="implantologie,strategie" count="6" orderby="views" show_views="true"]
```

```text
[parresia_block type="hero" source="query" taxonomy="post_tag" terms="a-la-une" count="1"]
```

```text
[parresia_block type="rail-list" top_mode="top-tags" count="5"]
```

```text
[parresia_block type="magazine" source="query" kiosk_post_type="kiosque"]
```

```text
[parresia_block type="ad" ad_mode="placement" ad_placement="sidebar-top"]
```

## Query options

- `source="manual|query"`
- `post_type="post"` or a custom post type such as `kiosque`
- `taxonomy="category|post_tag|custom_taxonomy"`
- `terms="slug-1,slug-2"`
- `count="5"`
- `orderby="date|views"`
- `views_meta_key="post_views_count"`
- `top_mode="most-read-posts|top-tags|top-categories|top-kiosks"`

## Install

Copy the `parresia-blocks` folder into `wp-content/plugins/`, then activate `Parresia Blocks` in WordPress.

## Notes

This first version intentionally avoids a build step. The editor script uses WordPress globals directly, so the plugin can be dropped into a site and tested quickly.
