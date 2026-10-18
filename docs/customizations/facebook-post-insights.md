# Facebook post insights compatibility

Required customization: preserve supported Facebook post and video metrics when
Meta rejects `post_video_views_unique`. On October 17, 2026, live read-only
requests confirmed this field returned code 100 while every other metric in the
per-video request succeeded. Because metrics are requested together, it stopped
both post discovery and analytics updates after October 14. Reporting's unchanged
counters then produced zero daily deltas for October 15–17.

`FacebookPageFetchPosts.php` and `FacebookPageFetchPostInsights.php` are baked
into each frozen image and included in the release-gate source fingerprints.
Both omit only the rejected metric.
Keep the historical enum, stored unique-view data, pagination, request timeouts,
page media views, reactions, comments, shares, and other supported video metrics.
Do not substitute page totals for per-post attribution or invent missing daily
history from a recovered lifetime total.

Every upgrade must compare both fetchers with the exact upstream candidate and
reapply this behavior. Run `FacebookPostInsightsTest.php` against the installed
candidate in the isolated rehearsal. Its fake API rejects the unsupported field
and exercises post discovery, pagination, daily video metrics, zero values, and
provider errors. Require the source register to match the exact passing image.

After cutover, verify successful natural imports, newly discovered posts, fresh
source insight timestamps, and Reporting readback. Any unresolved historical
daily gap must be reported separately from restored collection.

Retire these overrides only when the exact pristine upstream implementation
passes the same tests and current Meta requests succeed. Preserve the behavior
contract and historical data when retiring an implementation patch.
