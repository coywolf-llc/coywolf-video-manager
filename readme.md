<img src=".wordpress-org/icon-256x256.png" alt="Coywolf Video Manager logo" width="128" />

# Coywolf Video Manager

Manage, embed, and upload your [Cloudflare Stream](https://www.cloudflare.com/products/cloudflare-stream/) videos without leaving the WordPress admin. Search your Stream library, drop a video into any post with a Gutenberg block, track plays and likes, output video schema, generate captions, and serve a video XML sitemap.

- **Version:** 1.0.7
- **Requires WordPress:** 6.3+
- **Requires PHP:** 7.4+
- **License:** GPL-2.0-or-later

## Description

Coywolf Video Manager turns the WordPress admin into a control panel for your Cloudflare Stream account. Connect it with a Cloudflare API token and you can browse, search, edit, and upload videos, then embed them anywhere with a block — no trips to the Cloudflare dashboard.

### Features

- **Video block** — search your entire Stream library and embed a responsive video in a `<figure>` with the name in a `<figcaption>`. Per-block control over size (responsive or max-width), poster image (by timestamp or from the Media Library), start time, and playback options (controls, autoplay, loop, preload, mute, lazy-load).
- **Three players** — the Cloudflare Stream player by default, plus bundled open-source [Plyr](https://plyr.io/) and [Video.js](https://videojs.com/) options, all playing Cloudflare's adaptive HLS.
- **Likes, views & upload date** — a YouTube-style row under each video: a like button, the local view count, and when the video was uploaded to Cloudflare. Cloudflare's API doesn't expose likes or views, so the plugin records them locally in WordPress.
- **Video schema** — automatic `VideoObject` JSON-LD with a large (1200px) thumbnail, duration, upload date, and optional view/like interaction counts.
- **All Videos screen** — a sortable table of every video with views, likes, and how many posts and pages embed each one, linking through to filtered views.
- **Edit Video screen** — rename, set the creator, manage allowed origins, choose the poster (a timestamp with a live preview, or a Media Library image), copy the video ID, and add or AI-generate captions.
- **Upload to Cloudflare** — upload videos straight from WordPress, then jump to the Edit screen when processing finishes.
- **Video XML sitemap** — optionally serve `/video-sitemap.xml` listing every page and post that embeds a video.

Deleting a video removes its block from any post or page that used it.

<!-- wporg-strip:start -->
- **GitHub self-updater** — updates delivered straight from the project's GitHub releases.
<!-- wporg-strip:end -->

## Cloudflare API access

Settings are locked until you connect a Cloudflare account. You'll need two things:

1. **Account ID** — find it on the right-hand sidebar of any page in the [Cloudflare dashboard](https://dash.cloudflare.com/), or in the Stream section's API panel.
2. **API token** — create one at **My Profile → API Tokens → Create Token → Custom token** with the **Account › Stream › Edit** permission (read and manage videos, captions, and uploads).

Paste both into **Videos → Settings** and use **Test connection** to verify. The token is stored server-side and never exposed to the browser. You can instead define `COYWOLF_CVM_API_TOKEN` and `COYWOLF_CVM_ACCOUNT_ID` in `wp-config.php`, in which case the fields are locked.

## Installation

1. Upload the plugin to `wp-content/plugins/coywolf-video-manager` or install the zip from **Plugins → Add New → Upload Plugin**.
2. Activate it.
3. Go to **Videos → Settings**, enter your Cloudflare Account ID and API token, and click **Test connection**.
4. Configure your defaults (player, embed options, views/likes, sitemap), then add the **Video** block to a post.

## Frequently Asked Questions

### Does this store my videos in WordPress?

No. Videos live on Cloudflare Stream. WordPress stores only what Cloudflare doesn't: local play/like counts, which posts embed which videos, and your settings.

### How are plays and likes counted?

Cloudflare's API doesn't expose play or like counts, so the plugin records them locally. A play is counted once per visitor session after about two seconds of playback (so muted autoplay previews don't inflate the number). Likes are deduplicated per visitor. These are engagement signals, not billing-grade analytics.

### Will my embeds break if I deactivate the plugin?

The live player, counts, and schema are rendered by the plugin, but each block also saves a plain link to the video as a fallback, so content degrades gracefully.

## Privacy & third-party services

This plugin connects WordPress to **Cloudflare Stream**, a third-party service, on your behalf:

- The WordPress server calls the **Cloudflare API** (`api.cloudflare.com`) using your API token to list, edit, upload, and manage videos. See Cloudflare's [Privacy Policy](https://www.cloudflare.com/privacypolicy/).
- When the Cloudflare player is used, the front end loads the Stream player SDK from `embed.cloudflarestream.com` and streams video from `cloudflarestream.com` / `videodelivery.net`.
- The bundled **Plyr** (MIT) and **Video.js** (Apache-2.0) players and **hls.js** (Apache-2.0) are served locally from the plugin and make no third-party calls themselves; the video stream still comes from Cloudflare.

No data is sent anywhere except Cloudflare, and only as required to manage and play your videos.

## Styling with CSS

Style the on-post UI from **Videos → Settings → Appearance** (with a live preview and a color picker that accepts hex), or override it in your theme's CSS — or both. The block exposes CSS custom properties on the `.coywolf-cvm` wrapper:

| Property | Controls | Default |
| --- | --- | --- |
| `--cvm-title-color` | Video name color | `inherit` |
| `--cvm-title-size` | Video name size | `1.15rem` |
| `--cvm-title-weight` | Video name weight | `700` |
| `--cvm-align` | Alignment of the name and the like/views/date row | `left` |
| `--cvm-like-color` | Like icon & text color | `#0f0f0f` |
| `--cvm-like-bg` | Like button background (empty in Settings = none) | `#f2f2f2` |
| `--cvm-meta-color` | Views & date color | `#606060` |
| `--cvm-meta-size` | Views & date size | `0.9rem` |

Override them (or target the classes directly) from your theme:

```css
.coywolf-cvm {
  --cvm-title-size: 1.4rem;
  --cvm-like-bg: #000;
  --cvm-like-color: #fff;
}
```

Classes: `.coywolf-cvm` (wrapper), `.coywolf-cvm-title` (name), `.coywolf-cvm-like` (button), `.coywolf-cvm-thumb` (icon), `.coywolf-cvm-views`, `.coywolf-cvm-date`. An optional **light/dark scheme** adds `.coywolf-cvm-scheme-dark` or `.coywolf-cvm-scheme-auto` (which follows the visitor's `prefers-color-scheme`). The plugin's CSS avoids `!important`, so your theme can override it.

## Screenshots

1. The All Videos screen.
2. Editing a video.
3. The video block and its options in the editor.
4. Settings.

## Changelog

### 1.0.7
- Sitemap 404 fix; play-button color, alignment section + block override, like icon, transparent like bg (#8).

### 1.0.6
- Docs: remove readme references to the removed analytics and signed-URL features (#7).

### 1.0.5
- All Videos + Edit Video overhaul, remove signed-URL/analytics, heart icon, rename block (#6).

### 1.0.4
- jscolorpicker, live appearance preview, theme-overridable CSS, rem/numeric title, optional light/dark (#5).

### 1.0.3
- Outline like icon that fills when liked, plus appearance settings (#4).

### 1.0.2
- Load and harden the front-end CSS so it overrides the theme (#3).

### 1.0.1
- Bigger poster, remove lightbox, accurate responsive embed, and like/views/date row (#2).

### 1.0.0
- Initial release.
