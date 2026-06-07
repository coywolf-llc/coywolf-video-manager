=== Coywolf Video Manager ===
Contributors: jonhenshaw
Tags: cloudflare stream, video, video block, video sitemap, video schema
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.10
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage, embed, and upload Cloudflare Stream videos from WordPress — video block, play/like tracking, schema, captions, and a video sitemap.

== Description ==

Coywolf Video Manager turns the WordPress admin into a control panel for your Cloudflare Stream account. Connect it with a Cloudflare API token and you can browse, search, edit, and upload videos, then embed them anywhere with a block — no trips to the Cloudflare dashboard.

Features:

* Video block — search your entire Stream library and embed a responsive video in a figure/figcaption, with per-block control over size (responsive or max-width), poster (by timestamp or Media Library), start time, and playback options (controls, autoplay, loop, preload, mute, lazy-load).
* Three players — the Cloudflare Stream player by default, plus bundled open-source Plyr and Video.js options, all playing Cloudflare's adaptive HLS.
* Likes, views & upload date — a YouTube-style row under each video. Cloudflare's API does not expose likes or views, so the plugin records them locally in WordPress. Displayed likes never exceed the view count.
* Video schema — automatic VideoObject JSON-LD with a large (1200px) thumbnail, description, duration, upload date, and optional view/like interaction counts.
* All Videos screen — a table of every video with views, likes, and how many posts and pages embed each one.
* Edit Video screen — rename, add a description (used in schema + the sitemap), set the creator, manage allowed origins, choose the poster (a timestamp with a live preview, or a Media Library image at the recommended size), copy the video ID, and add or AI-generate captions.
* Upload to Cloudflare — upload videos straight from WordPress, then jump to the Edit screen when processing finishes.
* Video XML sitemap — optionally serve /coywolf-video-sitemap.xml (named to avoid clashing with Yoast SEO) listing every page and post that embeds a video, with full Google video tags (thumbnail, title, description, content and player URLs, duration, publication date, view count). Deleting a video also removes its block from any post or page that used it.

<!-- wporg-strip:start -->
* GitHub self-updater — updates delivered straight from the project's GitHub releases.
<!-- wporg-strip:end -->

== Cloudflare API access ==

Settings are locked until you connect a Cloudflare account. You need your Account ID (in the Cloudflare dashboard sidebar) and an API token created at My Profile > API Tokens with the "Account > Stream > Edit" permission. Paste both into Videos > Settings and click Test connection. The token is stored server-side and never exposed to the browser. You can instead define COYWOLF_CVM_API_TOKEN and COYWOLF_CVM_ACCOUNT_ID in wp-config.php.

== Installation ==

1. Upload the plugin to wp-content/plugins/coywolf-video-manager or install the zip from Plugins > Add New > Upload Plugin.
2. Activate it.
3. Go to Videos > Settings, enter your Cloudflare Account ID and API token, and click Test connection.
4. Configure your defaults, then add the Video block to a post.

== Frequently Asked Questions ==

= Does this store my videos in WordPress? =

No. Videos live on Cloudflare Stream. WordPress stores only what Cloudflare does not: local play/like counts, which posts embed which videos, and your settings.

= How are plays and likes counted? =

Cloudflare's API does not expose play or like counts, so the plugin records them locally. A play is counted once per visitor session after about two seconds of playback so muted autoplay previews do not inflate the number. Likes are deduplicated per visitor, and the displayed like count is capped at the view count so likes can never outnumber plays. These are engagement signals, not billing-grade analytics.

= Will my embeds break if I deactivate the plugin? =

The live player, counts, and schema are rendered by the plugin, but each block also saves a plain link to the video as a fallback, so content degrades gracefully.

== Privacy & third-party services ==

This plugin connects WordPress to Cloudflare Stream, a third-party service, on your behalf. The WordPress server calls the Cloudflare API (api.cloudflare.com) using your API token to manage videos. When the Cloudflare player is used, the front end loads the Stream player SDK from embed.cloudflarestream.com and streams video from cloudflarestream.com / videodelivery.net. The bundled Plyr (MIT), Video.js (Apache-2.0), and hls.js (Apache-2.0) players are served locally and make no third-party calls themselves. See Cloudflare's privacy policy at https://www.cloudflare.com/privacypolicy/.

== Styling with CSS ==

Style the on-post UI from Videos > Settings > Appearance (with a live preview and a hex color picker), or override it in your theme's CSS — or both. The block exposes CSS custom properties on the .coywolf-cvm wrapper: --cvm-title-color (default inherit), --cvm-title-size (1.15rem), --cvm-title-weight (700), --cvm-align (left; aligns the name and the like/views/date row), --cvm-like-color (#0f0f0f), --cvm-like-bg (#f2f2f2; empty in Settings = none), --cvm-meta-color (#606060), --cvm-meta-size (0.9rem). Example: .coywolf-cvm { --cvm-like-bg:#000; --cvm-like-color:#fff; } Useful classes: .coywolf-cvm, .coywolf-cvm-title, .coywolf-cvm-like, .coywolf-cvm-thumb, .coywolf-cvm-views, .coywolf-cvm-date. An optional light/dark scheme adds .coywolf-cvm-scheme-dark or .coywolf-cvm-scheme-auto. The plugin's CSS avoids !important so your theme can override it.

== Screenshots ==

1. The All Videos screen.
2. Editing a video.
3. The video block and its options in the editor.
4. Settings.

== Changelog ==

= 1.0.10 =
* Video sitemap: add duration, publication_date, content_loc, requires_subscription, live (#11).

= 1.0.9 =
* Right-align Cloudflare account details + Test connection; remove redundant Filter button (#10).

= 1.0.8 =
* Cap likes at view count; fix sitemap vs Yoast (rename + parse_request serve); Edit description + poster size hint (#9).

= 1.0.7 =
* Sitemap 404 fix; play-button color, alignment section + block override, like icon, transparent like bg (#8).

= 1.0.6 =
* Docs: remove readme references to the removed analytics and signed-URL features (#7).

= 1.0.5 =
* All Videos + Edit Video overhaul, remove signed-URL/analytics, heart icon, rename block (#6).

= 1.0.4 =
* jscolorpicker, live appearance preview, theme-overridable CSS, rem/numeric title, optional light/dark (#5).

= 1.0.3 =
* Outline like icon that fills when liked, plus appearance settings (#4).

= 1.0.2 =
* Load and harden the front-end CSS so it overrides the theme (#3).

= 1.0.1 =
* Bigger poster, remove lightbox, accurate responsive embed, and like/views/date row (#2).

= 1.0.0 =
* Initial release.
