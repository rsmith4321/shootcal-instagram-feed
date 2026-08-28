=== ShootCal Instagram Feed ===
Contributors: rsmith4321
Tags: instagram, feed, gallery, social media, hashtag
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight, cached Instagram feed for professional accounts with local hashtag filtering.

== Description ==

ShootCal Instagram Feed displays recent posts from one connected Instagram Business or Creator account. It fetches media on a schedule, stores the last successful response in WordPress, and renders the cached posts without visitor-triggered Instagram API requests.

Features:

* Responsive image grid with no front-end JavaScript.
* Images, video thumbnails, Reels, and carousel cover images.
* Exact, case-insensitive caption hashtag filtering.
* Shortcode-specific hashtags and display limits.
* Scheduled cache refresh with a last-known-good fallback.
* Manual refresh and connection status in WordPress Settings.

This first release uses the Instagram API with Facebook Login, a numeric Instagram business account ID, and a long-lived Page access token created for your Meta app. It does not scrape Instagram and it cannot read Personal accounts.

== Installation ==

1. Upload the `shootcal-instagram-feed` directory to `/wp-content/plugins/`.
2. Activate ShootCal Instagram Feed.
3. Open Settings > ShootCal Instagram Feed.
4. Enter the Instagram business account ID and a long-lived Page access token, then refresh the feed.
5. Add `[shootcal_instagram_feed]` to a Shortcode block.

To show only posts whose captions include a particular hashtag:

`[shootcal_instagram_feed hashtag="weddings"]`

The leading `#` is optional. Additional examples:

`[shootcal_instagram_feed hashtag="#weddings" limit="9" columns="3"]`

`[shootcal_instagram_feed limit="12" columns="4"]`

== Frequently Asked Questions ==

= Does filtering call Instagram's public hashtag search API? =

No. Filtering is performed locally against captions from the connected account's own recent posts. This keeps permissions and API usage small and predictable.

= What happens if Instagram is temporarily unavailable? =

The last successful cached metadata stays visible. The actual images remain hosted by Meta, and Meta's signed media URLs may eventually expire during a long outage or after access is revoked. An administrator can see the error and retry from Settings.

= Does a page visitor ever trigger a live Instagram request? =

No. Front-end rendering reads only the WordPress cache.

== External services ==

This plugin connects to Meta's Graph API at `graph.facebook.com` only during a scheduled or administrator-requested refresh. It sends the configured Instagram business account ID and Page access token to request the account username, captions, media type, media URLs, post links, timestamps, and carousel cover data. The token is decrypted only for these server-to-server requests and is never included in front-end HTML.

Feed images are served from the remote Meta/Facebook CDN URLs returned by the API. A visitor's browser therefore connects directly to Meta to load each visible image, which can disclose ordinary request information such as the visitor's IP address and browser user agent to Meta. The plugin applies a no-referrer policy to image requests.

Use of these services is subject to Meta's terms and privacy policy:

* Meta Platform Terms: https://developers.facebook.com/terms/
* Meta Privacy Policy: https://www.facebook.com/privacy/policy/

== Changelog ==

= 0.1.1 =

* Prevent a carousel video URL from being rendered as an image when Meta does not provide a thumbnail.

= 0.1.0 =

* Initial private testing release.
