<img src=".wordpress-org/icon-256x256.png" alt="Coywolf Video Manager logo" width="128" />

# Coywolf Video Manager

Manage, embed, and upload your [Cloudflare Stream](https://www.cloudflare.com/products/cloudflare-stream/) videos without leaving the WordPress admin. Search your Stream library, drop a video into any post with a Gutenberg block, track plays and likes, output video schema, generate captions, and serve a video XML sitemap.

- **Version:** 1.0.47
- **Requires WordPress:** 6.3+
- **Requires PHP:** 7.4+
- **License:** GPL-2.0-or-later

## Description

Coywolf Video Manager turns the WordPress admin into a control panel for your Cloudflare Stream account. Connect it with a Cloudflare API token and you can browse, search, edit, and upload videos, then embed them anywhere with a block — no trips to the Cloudflare dashboard.

### Features

- **Video block** — search your entire Stream library and embed a responsive video in a `<figure>` with the name in a `<figcaption>`. Per-block control over size (responsive or max-width), poster image (by timestamp or from the Media Library), start time, and playback options (controls, autoplay, loop, preload, mute, lazy-load).
- **Cloudflare Stream player** — the official Stream player, with an optional play-button accent color.
- **Likes, views & upload date** — a YouTube-style row under each video: a like button, the local view count, and when the video was uploaded to Cloudflare. Cloudflare's API doesn't expose likes or views, so the plugin records them locally in WordPress. Displayed likes never exceed the view count.
- **Video schema** — automatic `VideoObject` JSON-LD with a large (1200px) thumbnail, description, duration, upload date, and optional view/like interaction counts. Videos with captions also get a full plain-text `transcript` and a downloadable `caption` VTT track (linked straight from Cloudflare, or mirrored into the uploads folder when Cloudflare doesn't serve it publicly).
- **All Videos screen** — a sortable table of every video with views, likes, and how many posts and pages embed each one, linking through to filtered views.
- **Edit Video screen** — rename, add a description (used in schema + the sitemap), set the creator, manage allowed origins, choose the poster (a timestamp with a live preview, or a Media Library image at the recommended size), copy the video ID, and add or AI-generate captions.
- **Upload to Cloudflare** — upload videos straight from WordPress, then jump to the Edit screen when processing finishes.
- **Video XML sitemap** — optionally serve `/coywolf-video-sitemap.xml` listing every page and post that embeds a video, with full Google video tags (thumbnail, title, description, content & player URLs, duration, publication date, view count). Named to avoid clashing with Yoast SEO.
- **Safe deletes** — deleting a video removes its block from any post or page that used it. And if a video was deleted in the Cloudflare dashboard instead, the All Videos screen flags any posts still embedding it and offers a one-click, re-verified cleanup.
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
4. Configure your defaults (embed options, views/likes, appearance, sitemap), then add the **Coywolf Video** block to a post.

## Frequently Asked Questions

### Does this store my videos in WordPress?

No. Videos live on Cloudflare Stream. WordPress stores only what Cloudflare doesn't: local play/like counts, which posts embed which videos, and your settings.

### How are plays and likes counted?

Cloudflare's API doesn't expose play or like counts, so the plugin records them locally. A play is counted once per visitor session after about two seconds of playback (so muted autoplay previews don't inflate the number). Likes are deduplicated per visitor, and the displayed like count is capped at the view count (so likes can never outnumber plays). These are engagement signals, not billing-grade analytics.

### Will my embeds break if I deactivate the plugin?

The live player, counts, and schema are rendered by the plugin, but each block also saves a plain link to the video as a fallback, so content degrades gracefully.

## Privacy & third-party services

Privacy-first: this plugin includes no analytics, no tracking, and no data gathering — nothing about you, your site, or your visitors is ever collected. Its only outside connection is the service it exists to manage. The plugin connects WordPress to **Cloudflare Stream** on your behalf:

- The WordPress server calls the **Cloudflare API** (`api.cloudflare.com`) using your API token to list, edit, upload, and manage videos. See Cloudflare's [Privacy Policy](https://www.cloudflare.com/privacypolicy/).
- On the front end, the Cloudflare Stream player SDK loads from `embed.cloudflarestream.com` and video streams from `cloudflarestream.com` / `videodelivery.net`.

No data is sent anywhere except Cloudflare, and only as required to manage and play your videos.

## Styling with CSS

Style the on-post UI from **Videos → Settings → Appearance** (with a live preview and a color picker that accepts hex), or override it in your theme's CSS — or both. The block exposes CSS custom properties on the `.coywolf-cvm` wrapper:

| Property | Controls | Default |
| --- | --- | --- |
| `--cvm-title-color` | Name & description color | `inherit` |
| `--cvm-title-size` | Name & description size | `1rem` |
| `--cvm-title-weight` | Video name font weight | `700` |
| `--cvm-desc-weight` | Description font weight | `400` |
| `--cvm-align` | Alignment of the name & description | `left` |
| `--cvm-meta-align` | Alignment of the like/views/date row | `left` |
| `--cvm-like-color` | Like icon & text — unclicked (also used on hover) | `#0f0f0f` |
| `--cvm-like-bg` | Like background — unclicked / hover (empty in Settings = none) | `#f2f2f2` |
| `--cvm-like-active` | Like icon & text — clicked | unclicked color |
| `--cvm-like-active-bg` | Like background — clicked | unclicked background |
| `--cvm-meta-color` | Views & date color | `#606060` |
| `--cvm-meta-size` | Views & date size | `0.9rem` |
| `--cvm-radius` | Player corner radius | `0` |

Override them (or target the classes directly) from your theme:

```css
.coywolf-cvm {
  --cvm-title-size: 1.4rem;
  --cvm-like-bg: #000;
  --cvm-like-color: #fff;
}
```

Classes: `.coywolf-cvm` (wrapper), `.coywolf-cvm-title` (name/description figcaption), `.coywolf-cvm-name` (name), `.coywolf-cvm-desc` (description), `.coywolf-cvm-like` (button), `.coywolf-cvm-thumb` (icon), `.coywolf-cvm-views`, `.coywolf-cvm-date`. An optional **light/dark scheme** adds `.coywolf-cvm-scheme-dark` or `.coywolf-cvm-scheme-auto` (which follows the visitor's `prefers-color-scheme`). The plugin's CSS avoids `!important`, so your theme can override it.

## Screenshots

1. The All Videos screen.
2. Editing a video.
3. The video block and its options in the editor.
4. Settings.

## Changelog

### 1.0.47
- Picker: focus the search input on open; All Videos: linked thumbnail column (#48).

### 1.0.46
- Readme: state the privacy-first stance (no analytics, no data gathering) (#47).

### 1.0.45
- All Videos: replace Status column with Created, default-sorted newest first (#46).

### 1.0.44
- All Videos: flag dashboard-deleted videos still embedded, with confirmed cleanup (#45).

### 1.0.43
- All Videos: always fetch fresh (throttled), so dashboard deletes don't linger (#44).

### 1.0.42
- Audit hardening: editor-preview HTML sanitizer, ARIA status fixes, customer-code autoload (#43).

### 1.0.41
- Remove the animated poster option; hide All Videos Refresh when the webhook is enabled (#42).

### 1.0.40
- Settings: Cloudflare Stream webhook receiver (#41).

### 1.0.39
- Edit Video: MP4 downloads, used as the schema and sitemap video URL (#40).

### 1.0.38
- Upload: add from URL, chunked TUS uploads for large files, storage usage (#39).

### 1.0.37
- Block: optional animated GIF poster (#38).

### 1.0.36
- Schema: add transcript and downloadable caption tracks for captioned videos (#37).

### 1.0.35
- Poster timestamp and playback start time accept m:ss / h:mm:ss time format (#36).

### 1.0.34
- Block: group name/description fields with their toggles, instant caption preview, meta-row spacing when the caption is hidden (#35).

### 1.0.33
- Block: edit the video name and description from the Appearance panel (#34).

### 1.0.32
- Settings: check all Engagement options by default for new installs (#33).

### 1.0.31
- Engagement date controls, always-on schema views, split alignment, system meta font (#32).

### 1.0.30
- Accessibility audit: focus, reduced motion, dialog focus trap, labels (#31).

### 1.0.29
- Settings + block: player corner radius (with a --cvm-radius CSS property) (#30).

### 1.0.28
- Front end: set figcaption line-height to 1.5 (#29).

### 1.0.27
- Add a Documentation page (renders readme.md) to the Videos menu (#28).

### 1.0.26
- Front end: more space between the caption and the like/views/date row (#27).

### 1.0.25
- Upload: keep the entered video name instead of the file name (#26).

### 1.0.24
- Block: recommended poster dimensions for the custom-image source (#25).

### 1.0.23
- Sitemap: entity-encode non-ASCII even without the mbstring extension (#24).

### 1.0.22
- Sitemap: numeric entities for non-ASCII; Edit: don't send a blank creator to Cloudflare (#23).

### 1.0.21
- Description allows HTML (block) but strips it for schema/sitemap; auto-filtering video picker (#22).

### 1.0.20
- Public play/like: enforce REST nonce for logged-in users (#21).

### 1.0.19
- Security + performance audit fixes (JSON-LD hardening, query batching, per-request caching) (#20).

### 1.0.18
- Refine Like button hover/click interaction (fill-on-hover + hold-after-click) (#19).

### 1.0.17
- Remove Plyr and Video.js players; Cloudflare Stream player only (#18).

### 1.0.16
- Like button: unclicked + clicked color & background, with hover previewing the other state (#17).

### 1.0.15
- Name & description: display both in the caption with independent show toggles + weights (#16).

### 1.0.14
- Like button: separate Unclicked and Clicked colors (hover + filled-when-liked) (#15).

### 1.0.13
- Edit Video: return to All Videos after save/cancel/delete; modal delete confirmation (#14).

### 1.0.12
- Fix WordPress.org variant build (v1.0.11 leak-check false positive) (#13).

### 1.0.11
- Settings: indent Getting-started list; sitemap on by default + Search Console / Robots.txt Manager tip (#12).

### 1.0.10
- Video sitemap: add duration, publication_date, content_loc, requires_subscription, live (#11).

### 1.0.9
- Right-align Cloudflare account details + Test connection; remove redundant Filter button (#10).

### 1.0.8
- Cap likes at view count; fix sitemap vs Yoast (rename + parse_request serve); Edit description + poster size hint (#9).

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
