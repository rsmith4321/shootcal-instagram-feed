# Agent instructions

## Instagram feed functional parity (Ryan, 2026-09-27)

Keep ShootCal Websites and WordPress ShootCal Social Feed functionally aligned.
For every Instagram feed feature or behavior fix, inspect both current products,
implement the equivalent behavior where applicable, and verify both in the same
workstream. This includes View more/Load more, initial counts and columns,
filtering, media indicators, image fitting, responsive behavior, accessibility,
and cached refresh/loading behavior. Platform-specific administration, transport,
storage, and rendering can differ; the user-facing capability should not drift.
Use the shared IO-free filtering core and sync/check tooling when present; do not
force shared runtime infrastructure just to achieve parity. View more must use
cached posts without visitor-triggered provider requests. Preserve saved feeds,
connections, authored Website documents, and platform release safeguards.

Track implementation, integration, deployment, and public-package publication
separately for both products. A local candidate is not a shipped feature. Before
calling a feed change complete, verify matching behavior on both deployed
products, or explicitly report the remaining gap and its next action. Do not
silently accept a platform exception; obtain Ryan's direction for an intentional
feature difference. Inspect existing task-owned candidates before rebuilding
missing work, and carry later fixes into those candidates during integration.
