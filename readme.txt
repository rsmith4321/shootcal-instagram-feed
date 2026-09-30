=== ShootCal Social Feed ===
Contributors: rsmith4321
Tags: instagram, feed, gallery, social media, hashtag
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight, cached Instagram feed for professional accounts with local hashtag filtering.

== Description ==

ShootCal Social Feed displays recent posts from one connected Instagram Business or Creator account on your WordPress website. Connect with Facebook, choose your linked account, and add a shortcode. The plugin refreshes posts on a schedule and serves visitors the last successful WordPress cache without visitor-triggered Instagram API requests.

[ShootCal](https://www.shootcal.com/) also provides website building, scheduling, booking, contracts, invoices, client galleries, and a print store for photographers.

Features:

* Feed assets load only on pages containing a feed.
* Responsive image grid with no jQuery or heavy libraries. Static embeds need no JavaScript; dynamic embeds use one small deferred script.
* Images, video thumbnails, Reels, and carousel cover images.
* Exact, case-insensitive caption hashtag filtering with a single tag, any-of lists, and optional exclusions.
* Saved feeds: name a filter set once in ShootCal Apps > Social Feed, paste `[shootcal_instagram_feed feed="1"]` anywhere, and later edits apply everywhere.
* Shortcode-specific hashtags and display limits.
* One to six columns and initial images, with older saved display counts preserved.
* Optional View more button reveals one responsive row at a time, up to 30 cached matching posts.
* Additional images load when revealed, without a new Instagram request.
* Optional phone display count and account follow button.
* Optional post-load refresh that bypasses full-page caches while reading only WordPress's saved feed.
* Scheduled cache refresh with a last-known-good fallback.
* One-click Facebook authorization through ShootCal, plus manual-token fallback.
* Manual refresh and connection status in ShootCal Apps > Social Feed.
* Preview saved Smash Balloon feed imports, map existing shortcode IDs, and explicitly switch with an undo option.

Requirements: a WordPress site using HTTPS and an Instagram Business or Creator account linked to a Facebook Page that you can manage. The Connect with Facebook flow uses ShootCal's Meta app, so you do not need to create your own developer app. Advanced users can enter their own numeric Instagram account ID and Page access token instead. The plugin uses the official Instagram API with Facebook Login. It does not scrape Instagram or support Personal accounts.

== Installation ==

1. Upload the `shootcal-social-feed` directory to `/wp-content/plugins/`.
2. Activate ShootCal Social Feed.
3. Open ShootCal Apps > Social Feed.
4. Choose Connect with Facebook and select the linked professional Instagram account.
5. Add `[shootcal_instagram_feed]` to a Shortcode block.

To show only posts whose captions include a particular hashtag:

`[shootcal_instagram_feed hashtag="weddings"]`

Comma-separated lists match any of the tags, and `exclude` removes posts even when they match:

`[shootcal_instagram_feed hashtag="familyportraits, family" exclude="wedding"]`

Prefer managing filters without editing pages? Create a saved feed under ShootCal Apps > Social Feed and embed it by id:

`[shootcal_instagram_feed feed="1"]`

The leading `#` is optional. Additional examples:

`[shootcal_instagram_feed hashtag="#weddings" limit="9" columns="3"]`

`[shootcal_instagram_feed limit="12" columns="4"]`

To mirror a compact social feed with five desktop tiles, four mobile tiles, and an account button:

`[shootcal_instagram_feed hashtag="wedding" limit="5" columns="5" mobile_limit="4" follow="true"]`

If the surrounding page is held in a full-page cache, add `dynamic="true"`. The cached page keeps a server-rendered fallback, while a small deferred script refreshes the markup after load from a public, non-stored WordPress REST response. That route reads only the last successful plugin cache and never triggers an Instagram API request:

`[shootcal_instagram_feed hashtag="wedding" limit="5" columns="5" mobile_limit="4" follow="true" dynamic="true"]`

== Frequently Asked Questions ==

= Can I migrate from Smash Balloon? =

Yes. In ShootCal Apps > Social Feed, select the Import from Smash Balloon button after connecting the same Instagram account. The separate walkthrough guides you through preparation, feed selection, previews, and switching. Saved definitions can be read while Smash Balloon is active, inactive, or removed, provided its data remains in the database. Supported single-account grid feeds can become new ShootCal presets. You can also explicitly map an old feed ID to an existing ShootCal preset, including when the old settings cannot be converted automatically.

Leave Smash Balloon active during import and preview if it serves your live pages. Do not delete it to start the import: deletion can erase its data unless its Preserve settings if plugin is removed option is enabled.

Importing saves presets and ID mappings only. Review the cached previews, then choose Switch shortcodes to ShootCal. If Smash Balloon is active, the form requires an explicit choice to deactivate it. The importer preserves its saved data and does not copy credentials or rewrite page content. Undo switch reactivates only the Smash Balloon plugin that this importer deactivated; imported presets remain available.

After switching, mapped `[instagram-feed feed="12"]` shortcodes and the standard Smash Balloon Instagram blocks render through ShootCal. Supported inline overrides are `num`, `cols`, `nummobile`, `showfollow`, and `class`. Bare legacy shortcodes without a saved feed ID and unsupported inline options require manual updates. Native Smash Balloon widgets and Elementor widgets must first be replaced with Shortcode blocks/widgets. Network-activated Smash Balloon installations cannot be switched by this site-level importer.

This is a migration aid, not full feature or visual parity. ShootCal uses one connected professional account, its own responsive grid and cached recent posts, and exact caption hashtag filters. Word/phrase filters, public hashtag or tagged feeds, multiple-account sources, moderation, shopping, and other unsupported selection settings require an explicitly chosen replacement. Headers, captions, likes, lightboxes, Load More, and custom styles are not copied. The importer scans stored content and widgets; review any theme or external template embeds separately. Clear your page cache and verify affected pages after switching or undoing.

= I use a performance plugin (Perfmatters, WP Rocket, LiteSpeed Cache, Autoptimize) and the feed looks unstyled or images misbehave. =

ShootCal Social Feed registers its own exclusions with Perfmatters and WP Rocket automatically: its stylesheet is excluded from Remove Unused CSS, and its images carry the standard `skip-lazy` marker that most lazy-load plugins honor. After updating this plugin, clear your optimizer's CSS cache once so it regenerates.

For other optimizers, exclude these manually:

* Unused/critical CSS removal: exclude the stylesheet path `/shootcal-social-feed/` (or safelist selectors beginning with `.shootcal-instagram-feed`).
* Lazy loading: exclude images with the `skip-lazy` class if your tool does not already honor it. The plugin times its own image loading.
* JavaScript delay/defer tools: `feed.js` is small and already deferred; if your tool delays scripts until user interaction, exclude `shootcal-social-feed/assets/feed.js` so the feed can load on scroll.

= Does filtering call Instagram's public hashtag search API? =

No. Filtering is performed locally against captions from the connected account's own recent posts. This keeps permissions and API usage small and predictable.

= What happens if Instagram is temporarily unavailable? =

The last successful cached metadata stays visible. The actual images remain hosted by Meta, and Meta's signed media URLs may eventually expire during a long outage or after access is revoked. An administrator can see the error and retry from ShootCal Apps > Social Feed.

= Does a page visitor ever trigger a live Instagram request? =

No. Front-end rendering reads only the WordPress cache. Visitors' browsers still load feed images from Meta's CDN, as described under External services.

= How do I delete the Instagram connection data? =

Open ShootCal Apps > Social Feed and choose Disconnect and clear cache. This removes the encrypted token, selected Instagram account ID, OAuth state, refresh status, and cached feed from this WordPress installation. Deleting the plugin from WordPress also removes all plugin options and its scheduled refresh job. See https://shootcal.com/data-deletion/ for the complete instructions.

== External services ==

The plugin contacts ShootCal only when an administrator starts or completes Connect with Facebook. During one-click connection, the plugin sends this site's WordPress admin callback URL, WordPress Address, plugin version, and a one-time cryptographic challenge to the ShootCal OAuth broker at `api.shootcal.com`. ShootCal redirects the administrator to Meta, temporarily handles the resulting Page-token candidate, and releases it only to this WordPress server after the server proves possession of the one-time verifier. Tokens are never placed in browser URLs. The broker attempt expires after ten minutes.

After connection, this plugin connects to Meta's Graph API at `graph.facebook.com` during a scheduled or administrator-requested refresh. It sends the configured Instagram business account ID and Page access token to request the account username, captions, media type, media URLs, post links, timestamps, and carousel cover data. The token is decrypted only for these server-to-server requests and is never included in front-end HTML.

Feed images are served from the remote Meta/Facebook CDN URLs returned by the API. A visitor's browser therefore connects directly to Meta to load each visible image, which can disclose ordinary request information such as the visitor's IP address and browser user agent to Meta. The plugin applies a no-referrer policy to image requests.

Service terms and privacy policies:

* ShootCal Terms of Service: https://shootcal.com/terms/
* ShootCal Privacy Policy: https://shootcal.com/privacy/
* ShootCal data-deletion instructions: https://shootcal.com/data-deletion/
* Meta Platform Terms: https://developers.facebook.com/terms/
* Meta Privacy Policy: https://www.facebook.com/privacy/policy/

== Support ==

For help with connection or feed display, contact support@shootcal.com. Include your WordPress and plugin versions and a description of the issue. Never send your access token or Facebook password.

== Changelog ==

= 0.5.0 =
* Preserve entire photos inside square tiles, and protect media badges from theme hover stacking.
* Add optional View more, responsive row expansion, and matching ShootCal feed controls.
* Share the small hashtag filtering library and preserve existing feed settings.


= 0.4.2 =
* Keep carousel and video indicators above feed photos when a theme raises linked images on hover.
* Isolate each feed tile’s stacking context without changing theme styles outside the feed.

= 0.4.1 =
* Move Smash Balloon import behind a single settings-page button and a four-step guided walkthrough.
* Explain preserved shortcodes, importing retained data after removal, and deactivation at the final switch.
* Keep source feeds, previews, and switch controls off the regular settings page.

= 0.4.0 =
* Add reviewed Smash Balloon feed import and explicit existing-feed mapping without rewriting content or copying credentials.
* Add opt-in shortcode and standard-block compatibility, guarded plugin switching, cached previews, and undo.
* Detect unsupported sources, filters, inline settings, native widgets, and Elementor widgets before switching.

= 0.3.9 =
* Keep the multiphoto icon unchanged when hovering or focusing a feed image.
* Add a subtle cell border so white-padded Instagram photos have a clear edge.

= 0.3.8 =
* Prepare the public plugin-directory submission with current setup instructions, account requirements, and external-service disclosures.
* Use the ShootCal Social Feed directory slug while preserving existing connections, saved feeds, shortcodes, and cache keys.

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
