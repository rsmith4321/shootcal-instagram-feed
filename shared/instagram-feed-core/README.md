# Instagram feed parity

The canonical source is `shootcal-backend/packages/instagram-feed-core`.
The versioned PHP namespace is distributed as identical local files in Galleries
and the WordPress plugin. It performs no network, filesystem, or database IO.
No package server or runtime Composer install is needed.

Edit the canonical source, update its version and SHA-256 in `contract.json`,
then run `php bin/sync-instagram-core.php GALLERIES_CHECKOUT WORDPRESS_CHECKOUT`.
Before release run the same command with `--check`. Each consumer's regression
gate also validates the vendored source against the pinned contract and fixtures.
The shared code is GPL-2.0-or-later; platform application code stays separate.

Every Instagram feature change must assess both products and update their tests
in the same workstream. Required matching behavior:

- Exact, case-insensitive Unicode caption hashtags, any included tag matches,
  excluded tags win, empty filters include all posts, malformed filters fail closed.
- 1 to 6 desktop columns, at most 3 on tablets and 2 on phones; one column stays one.
- 1 to 6 initial images in new feed controls. Existing WordPress counts through
  30 and its optional phone count remain supported without a data migration.
- Optional View more reveals one current-width row, stops at 30 cached matching
  posts, disappears at exhaustion, and announces the new count accessibly.
- Additional images are not mounted/requested until revealed. A click makes no
  Meta API request and no new feed request. No heavy frontend library is added.
- Connection, refresh scheduling, account isolation, rendering, and platform
  management controls remain owned by their host application.

Test both renderers, phone/desktop columns, partial last rows, exhaustion,
independent feeds, filter changes, legacy settings, and no-JavaScript fallbacks.
Editor previews are synthetic coverage unless verified against a real connection.
