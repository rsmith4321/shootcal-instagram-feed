# RSP Social Feed 0.6.8 to 0.6.9

This is one narrowly pinned RSP release, not a general plugin updater. Its only
live tree is `/srv/blog/site/wp-content/plugins/shootcal-social-feed`, active
identifier `shootcal-social-feed/shootcal-instagram-feed.php`, on AMD
`207.246.65.209`. The inactive four-asset `shootcal-instagram-feed` directory is
outside the operator's paths and remains untouched. The older RSP `rsp-code`
operator does not ship this plugin; its code and receipts are not changed here.

The contract pins exactly 29 runtime files from installed 0.6.8 commit
`82fad4e0b10dd43b09dd859b46e5a27b266a829d` to 0.6.9 runtime commit
`97644184d1627f98abf5f95cd8422a74aed3b7eb`. The public archive contains that exact
runtime, under `shootcal-social-feed/`. Tools and tests never enter it. A release
from a different baseline or with any extra file requires a reviewed successor;
there is no force switch, arbitrary destination, deletion or uninstall command.

## Reviewed source and commands

Run tests, review and commit/push this tool first. Every command below refuses a
dirty checkout, an unpushed branch HEAD, or runtime bytes that differ from the
fixed 0.6.9 source. HEAD must descend from the pinned runtime commit. Preserve
the applicable shared deploy lane and any explicit release hold.

```sh
python3 -I -B tests/rsp-feed-release-test.py
php tests/package-version.php
php tests/image-cleanup-test.php
bash -n tools/deploy-run.sh
```

```sh
tools/deploy-run.sh package
tools/deploy-run.sh check
tools/deploy-run.sh apply
```

`package` makes a local private review artifact and reports its directory,
source commit and exact zip SHA256. `check` uploads the same source-pinned bundle
to a private fixed server directory and checks eligibility; it does not install,
persist option backups, purge caches or run WordPress/provider jobs. `apply` installs
the pinned candidate. The normal wrapper is the entry point; do not copy edited
helper files into an old run or repair a receipt by hand.

If the connection or process dies, retain the same clean pushed source commit:

```sh
tools/deploy-run.sh check
tools/deploy-run.sh resume
```

The exact source bundle lives under
`/root/shootcal-social-feed-release-20261109/<source-head>/`, root:root 0700.
Existing uploaded files must match; they are not overwritten. Upload staging is
serialized and durably promotes each complete private file. The source helper,
runtime contract, package and journal retain their separate hashes. Options and
credentials are never downloaded to the Mac.

## Admission and preservation

- SSH target, AMD hostname and machine-id hash are pinned. The existing WP-CLI
  binary is also hash-pinned: its reviewed database helper passes its password
  via `MYSQL_PWD`, removes it from the argument array before constructing the
  MySQL command, and does not expose it in process arguments.
- Apply/resume use the existing shared deployment lock without recreating its
  inode, then repeat admission and exact file/data comparisons inside it. The
  hourly backup exporter uses the same lock. Starts are :48 through :18 UTC;
  :25 through :47 are protected, and daily backup/image lead windows are also
  excluded. Admission rechecks the current clock after potentially slow reads
  and immediately before exchange. Host commands share a 120-second operation
  deadline; purge is separately bounded and rechecks its mutation window.
- Published backup status must be fresh, all three production plans successful
  and within their age bounds, with no recent failures. The actual manager must
  be idle. A read-only GetOperations call separately joins the latest successful
  non-dry-run hourly backup's full snapshot ID to its successful index.
- Every plugin file/directory must match its pinned bytes, owner, group, mode,
  type and device. Symlinks, hard links, foreign files/directories and hash drift
  refuse. The new tree and retained old tree share filesystem device 64770.
- Options are read as raw SQL HEX rows via the existing `rspblog` PHP/WP-CLI
  path, before WordPress bootstrap, with plugins/themes/packages skipped and
  sendmail fenced. Database/schema identity is checked. The capture includes all
  `shootcal_instagram_feed*` rows and the complete `active_plugins` row. Only
  row counts, hashes, active status/count and source pins may leave the host.
- Before live exchange, the raw capture is encrypted with AES-256-CBC/PBKDF2
  using a random server-only key; key, ciphertext and journal are root 0600.
  Key/ciphertext hashes plus decrypted-content verification guard resumption.
  These are protected recovery artifacts, not an option restoration command.
- Saved connections, feed options, cache options, active plugin list and actual
  cached rendition bytes are compared before and after. The fixed image-cache
  realpath is `/srv/blog/wp-content/uploads/shootcal-social-feed`; reads are
  bounded to 512 renditions/64 MiB. No uploads or option rows are written.

## Durable states

| State | Recovery behavior |
|---|---|
| `building` | No production exchange is admitted. Recheck the exact old live tree; build a new unique private candidate and only promote a fully hashed/fsynced tree. A restart may capture current options/cache because no prepared preservation promise or live change exists yet. |
| `prepared` | Encrypted options and exact preservation hashes are durable before exchange. Infer whether old/new physical trees were exchanged, including a crash immediately after the atomic syscall. Never swap twice based only on a stale journal field. |
| `exchanged` | Keep the old copy protected under the root-only run directory. Recheck the original preservation hashes and retry only unfinished RSP cache invalidation. Any data/source drift refuses without restoring over the new data. |
| `complete` | Verify exact new live tree, protected old tree and sealed receipt, then return the recorded preservation-at-commit result without writes. Normal later feed refreshes/settings edits do not cause a completed release to rerun purge or fail forever. This is historical commit evidence, not a current-data equality claim. |

Interrupted candidate directories are retained privately, never deleted or
adopted blindly. Eight candidate attempts (at most 64 MiB) and sixteen partial
upload files, and eight interrupted private temporary files per capture/journal
name, are bounded storage limits; exceeding it needs inspection,
not cleanup by this tool. Prepared/exchanged data drift likewise needs review;
there is no blanket rebase/adoption switch. Options may change normally again
after completion.

The source swap is one kernel directory exchange followed by parent fsyncs.
Opcache must retain validate_timestamps=On/revalidate_freq=2. The preflight reads
only the verified PHP-FPM root and its exact rsp/axis pool include graph, refuses
unknown includes/pools and value/flag overrides, and ignores unrelated nginx
files. There is no FPM or nginx reload. Page-cache invalidation is only
the RSP host, and Redis SCAN/UNLINK is restricted to `rsp-plainwp:`. Failed HTTP,
Redis or partial-batch results remain unfinished in the journal and are retried
idempotently. There is no shared flush, warm service, WordPress cron, refresh,
provider request, email or real-message action.

After a successful install, the release owner still verifies the public feed at
desktop/phone widths and its cached expansion behavior, confirms no provider
requests, and records exact source/runtime/package pins and the preservation
hash/count result. A local test, package or completed source branch is not live
release proof. This operator covers RSP only; customer Websites and the public
WordPress directory release remain separate delivery lanes.
