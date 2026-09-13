=== ShootCal Social Feed ===
Contributors: rsmith4321
Tags: instagram, feed, gallery, social media, hashtag
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.3.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight, cached Instagram feed for professional accounts with local hashtag filtering.

== Description ==

ShootCal Social Feed brings the same slick, fast-loading Instagram feed from [ShootCal's website builder](https://www.shootcal.com/) to WordPress. It displays recent posts from one connected Instagram Business or Creator account, fetches media on a schedule, stores the last successful response in WordPress, and renders the cached posts without visitor-triggered Instagram API requests — the whole feed, script and images together, loads only when a visitor scrolls near it.

Photographer? [ShootCal](https://www.shootcal.com/) also handles your scheduling, calendar, booking, contracts, invoices, client galleries, and print store — this feed is one small piece of it.

Features:

* Loads only where used: the stylesheet and the single small script enqueue solely on pages that actually render a feed — nothing is added site-wide.
* Responsive image grid; no jQuery and no heavy libraries — static embeds ship zero JavaScript, dynamic embeds one ~4 KB deferred script.
* Images, video thumbnails, Reels, and carousel cover images.
* Exact, case-insensitive caption hashtag filtering — a single tag, or any-of lists with optional exclusions.
* Saved feeds: name a filter set once in Settings, paste `[shootcal_instagram_feed feed="1"]` anywhere, and later edits apply everywhere.
* Shortcode-specific hashtags and display limits.
* Optional five-desktop/four-mobile layout and account follow button.
* Optional post-load refresh that bypasses full-page caches while reading only WordPress's saved feed.
* Scheduled cache refresh with a last-known-good fallback.
* One-click Facebook authorization through ShootCal, plus manual-token fallback.
* Manual refresh and connection status in WordPress Settings.

This first release uses the Instagram API with Facebook Login, a numeric Instagram business account ID, and a long-lived Page access token created for your Meta app. It does not scrape Instagram and it cannot read Personal accounts.

== Installation ==

1. Upload the `shootcal-instagram-feed` directory to `/wp-content/plugins/`.
2. Activate ShootCal Social Feed.
3. Open ShootCal > Social Feed.
4. Choose Connect with Facebook and select the linked professional Instagram account.
5. Add `[shootcal_instagram_feed]` to a Shortcode block.

To show only posts whose captions include a particular hashtag:

`[shootcal_instagram_feed hashtag="weddings"]`

Comma-separated lists match any of the tags, and `exclude` removes posts even when they match:

`[shootcal_instagram_feed hashtag="familyportraits, family" exclude="wedding"]`

Prefer managing filters without editing pages? Create a saved feed under ShootCal > Social Feed and embed it by id:

`[shootcal_instagram_feed feed="1"]`

The leading `#` is optional. Additional examples:

`[shootcal_instagram_feed hashtag="#weddings" limit="9" columns="3"]`

`[shootcal_instagram_feed limit="12" columns="4"]`

To mirror a compact social feed with five desktop tiles, four mobile tiles, and an account button:

`[shootcal_instagram_feed hashtag="wedding" limit="5" columns="5" mobile_limit="4" follow="true"]`

If the surrounding page is held in a full-page cache, add `dynamic="true"`. The cached page keeps a server-rendered fallback, while a small deferred script refreshes the markup after load from a public, non-stored WordPress REST response. That route reads only the last successful plugin cache and never triggers an Instagram API request:

`[shootcal_instagram_feed hashtag="wedding" limit="5" columns="5" mobile_limit="4" follow="true" dynamic="true"]`

== Frequently Asked Questions ==

= I use a performance plugin (Perfmatters, WP Rocket, LiteSpeed Cache, Autoptimize) and the feed looks unstyled or images misbehave. =

ShootCal Social Feed registers its own exclusions with Perfmatters and WP Rocket automatically: its stylesheet is excluded from Remove Unused CSS, and its images carry the standard `skip-lazy` marker that most lazy-load plugins honor. After updating this plugin, clear your optimizer's CSS cache once so it regenerates.

For other optimizers, exclude these manually:

* Unused/critical CSS removal: exclude the stylesheet path `/shootcal-instagram-feed/` (or safelist selectors beginning with `.shootcal-instagram-feed`).
* Lazy loading: exclude images with the `skip-lazy` class if your tool does not already honor it. The plugin times its own image loading.
* JavaScript delay/defer tools: `feed.js` is small and already deferred; if your tool delays scripts until user interaction, exclude `shootcal-instagram-feed/assets/feed.js` so the feed can load on scroll.

= Does filtering call Instagram's public hashtag search API? =

No. Filtering is performed locally against captions from the connected account's own recent posts. This keeps permissions and API usage small and predictable.

= What happens if Instagram is temporarily unavailable? =

The last successful cached metadata stays visible. The actual images remain hosted by Meta, and Meta's signed media URLs may eventually expire during a long outage or after access is revoked. An administrator can see the error and retry from Settings.

= Does a page visitor ever trigger a live Instagram request? =

No. Front-end rendering reads only the WordPress cache.

= How do I delete the Instagram connection data? =

Open ShootCal > Social Feed and choose Disconnect and clear cache. This removes the encrypted token, selected Instagram account ID, OAuth state, refresh status, and cached feed from this WordPress installation. Deleting the plugin from WordPress also removes all plugin options and its scheduled refresh job. See https://shootcal.com/data-deletion/ for the complete instructions.

== External services ==

During one-click connection, the plugin sends this site's WordPress admin callback URL, WordPress Address, plugin version, and a one-time cryptographic challenge to the ShootCal OAuth broker at `api.shootcal.com`. ShootCal redirects the administrator to Meta, temporarily handles the resulting Page-token candidate, and releases it only to this WordPress server after the server proves possession of the one-time verifier. Tokens are never placed in browser URLs. The broker attempt expires after ten minutes.

After connection, this plugin connects to Meta's Graph API at `graph.facebook.com` during a scheduled or administrator-requested refresh. It sends the configured Instagram business account ID and Page access token to request the account username, captions, media type, media URLs, post links, timestamps, and carousel cover data. The token is decrypted only for these server-to-server requests and is never included in front-end HTML.

Feed images are served from the remote Meta/Facebook CDN URLs returned by the API. A visitor's browser therefore connects directly to Meta to load each visible image, which can disclose ordinary request information such as the visitor's IP address and browser user agent to Meta. The plugin applies a no-referrer policy to image requests.

Use of these services is subject to Meta's terms and privacy policy:

* Meta Platform Terms: https://developers.facebook.com/terms/
* Meta Privacy Policy: https://www.facebook.com/privacy/policy/

== Changelog ==

= 0.3.7 =
* Name the slider app Photo Slider to match the ShootCal Slider admin screen.

= 0.3.6 =
* Move ShootCal Apps below Settings in the WordPress sidebar.
* Show Natural Photo Slider in the apps overview when it is installed.

= 0.3.5 =
* Group Social Feed and Calendar under one ShootCal sidebar menu, with an overview of the available plugins.
* Keep the existing Social Feed settings address and saved connection unchanged. Either ShootCal plugin can provide the shared menu independently.

= 0.3.4 =
* Register exclusions with CSS optimizers automatically: the stylesheet is excluded from Perfmatters and WP Rocket unused-CSS removal, feed images carry the standard skip-lazy marker for lazy-load plugins, and the FAQ documents manual exclusions for other tools.
* Document that assets load only on pages rendering a feed; nothing is enqueued site-wide.

= 0.3.3 =
* Drop the glass chip behind the carousel icon: the double-photo mark now sits directly on the image in a dark shade with a soft light halo, deepening slightly on hover.

= 0.3.2 =
* Lazy-load the entire dynamic feed: nothing is fetched and no feed image loads until the feed nears the viewport, then the fresh markup and its images load together. Script-injected images are invisible to native and plugin lazy-loaders, so the script now owns image timing end to end; pages whose visitors never reach the feed no longer call the REST route at all.
* Replace the Carousel text chip with a light glass double-photo icon that stays visible and darkens slightly on hover; badges can no longer slip under the zoomed hover image.
* Move the plugin into its own top-level "Instagram Feed" admin menu item instead of a Settings submenu.

= 0.3.1 =
* Fix images never loading after the deferred AJAX refresh: Chromium does not natively lazy-load images parsed via innerHTML, so the plugin now promotes them itself once the feed nears the viewport.

= 0.3.0 =
* Add saved feeds: create named hashtag filter sets in Settings and embed them with `[shootcal_instagram_feed feed="N"]`; editing a saved feed updates every page using it, and dynamic embeds pick up edits through full-page caches.
* Support comma-separated any-of hashtag lists and a new `exclude` attribute in the shortcode, the REST route, and the default-hashtag setting.
* Hide empty-feed and configuration messages from visitors; administrators still see them.
* Use the first carousel child with a usable image as the cover instead of only the first child.
* Warn administrators on every dashboard page when the feed has not refreshed for two days, before Meta's signed image URLs expire.

= 0.2.2 =
* Allow validated OAuth and disconnect writes through the settings sanitizer, with exact database read-back verification.

= 0.2.1 =
* Distinguish secure broker-response failures from local encrypted-storage failures during one-click setup without exposing tokens or provider data.

= 0.2.0 =
* Rename the plugin to ShootCal Social Feed while preserving existing shortcodes, settings, and cached data.
* Add one-click Facebook authorization through ShootCal with one-time server-to-server token redemption.
* Keep the existing encrypted local token storage, local feed cache, and manual-token fallback.
* Clear the selected account boundary on disconnect and document complete plugin data removal.

= 0.1.4 =
* Load dynamic-feed CSS after page load so full-page cache CSS optimizers cannot strip the feed layout.

= 0.1.3 =

* Load feed assets before the document head closes when a page contains the shortcode.

= 0.1.2 =

* Add optional responsive mobile item limits and an account follow button.
* Add an opt-in JavaScript refresh backed by a cache-only REST endpoint for full-page-cached sites.

= 0.1.1 =

* Prevent a carousel video URL from being rendered as an image when Meta does not provide a thumbnail.

= 0.1.0 =

* Initial private testing release.
