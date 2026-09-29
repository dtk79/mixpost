# Threads video optimization

Deployed September 11, 2026, using the existing production override mechanism.

`ops/production-overrides/ThreadsManagesContainer.php` replaces the Pro Team
Threads `Concerns/ManagesContainer.php` trait. Both single posts and carousel
children use this shared container builder. Videos select `social_video` first,
then the older `instagram_reel` conversion, matching Instagram Reels. Images,
text, captions, alt text, carousel parents, and publishing/status requests retain
their upstream behavior.

The existing upload pipeline creates `social_video` conversions. This override
selects those files; it does not start additional conversions while publishing.
As with the existing Instagram Reels selector, legacy media without a conversion
falls back to the original at or below 300 MiB, while larger originals return
`social_video_optimization_pending` before contacting Threads. Historical media
that needs conversion should run the existing `OptimizeSocialVideoMediaJob`
before a provider-specific retry.

Production mount in `/root/mixpost/docker-compose.yml`:

```yaml
- ./ThreadsManagesContainer.php:/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Threads/Concerns/ManagesContainer.php:ro
```

Keep the host override and mount during upgrades, comparing it against the new
upstream trait. The deployment backup is
`/root/mixpost/backups/threads-video-20260911/`, containing the prior Compose file
and original trait. Rollback removes only this mount and recreates the app using
its current image; preserve other services, mounts, and data.

Validation uses PHP lint and `tests/ThreadsManagesContainerTest.php` against the
installed Pro dependencies with all provider HTTP faked. Set
`THREADS_CONTAINER_PATH` to the trait under test. Eight request cases cover
optimized single/carousel videos, legacy conversion/original fallbacks, oversized
original rejection before HTTP, images/alt text, text, and carousel parents.

The originating incident is post 1667, Threads account 103, post-account row 2948,
media 711. The original 2160x3840 video is 152,192,526 bytes at about 38 Mbps;
the existing 1080x1920 conversion is 32,301,480 bytes at about 8 Mbps. Meta's
original container returned `ERROR / UNKNOWN`, which did not prove its cause.
Retry only the failed Threads row after checking the account's live timeline;
preserve successful X, YouTube, Instagram, and Facebook rows.

The authorized Threads-only recovery succeeded on September 11, 2026. After a
duplicate check across 100 live posts, the normal `AccountPublishPost` action
stored provider ID `18174832741432638`, cleared row 2948's error, and left the
other four provider rows unchanged. Published URL:
https://www.threads.com/@richmerrittauthor/post/DdJrYXdgkOL

The provider API independently returned HTTP 200 with the expected text and
`VIDEO` media type. After verifying all five account rows had provider IDs and
no errors, the post's normal `setPublished()` method reconciled the parent from
Failed to Published. That status-only reconciliation preserved all provider rows.
