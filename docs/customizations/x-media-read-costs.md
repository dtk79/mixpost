# X media expansion cost controls

X announced that media objects returned through expansions become billable on
November 22, 2026. Repeating media expansions for cached posts would add charges
on each later refresh day without improving our analytics.

## Required behavior

The age-tiered timeline requests metrics, full `note_tweet` text, article content
and attachment identifiers with no expansions or `media.fields`. The import job
loads the existing imported-post metadata scoped to the workspace and account.
Known thumbnails (both provider URLs and downloaded paths) and media types are
preserved while text and metrics update.

New posts or missing media metadata use a separate `GET /2/tweets` lookup, at
most 100 IDs per request. Only attachment media and article covers are expanded;
article body images, authors, polls, places and referenced posts are not requested.
Plain-text posts do not need this lookup. Failed metadata retrieval leaves the
metrics import intact, preserves cached values, and can recover on a later run.
This still incurs the necessary first media read; same-day post deduplication is
an X billing policy rather than a guarantee made by this implementation.

The two existing read-only overrides remain in the deployment manifest:
`ManagesTwitterResources.php` and `ImportTwitterPostsJob.php`. No schema migration
or new service setting is required. Keep existing analytics range controls,
historical public-metrics fallback, pagination windows and disabled mentions jobs.

## Every upgrade

Rebase the complete existing customizations onto the exact target Pro source,
then run this idempotent command against the **staged effective package**:

```sh
python3 ops/scripts/apply-twitter-media-cost-fixes.py --source "$staged_pro"
```

Already-applied patches are a no-op. Changed upstream context blocks the command;
rebase the two versioned patches and retain their behavior tests. Never copy an
older whole provider/job over a newer Pro implementation without that review.

The frozen validator and mounted startup guard require `twitter-media-reads`
(17 offline behavior checks) and mandatory fingerprints for the provider and
import job. `TwitterMediaPatchApplicationTest.py` checks idempotence and drift
rejection in CI. The isolated restored-database rehearsal must also run
`TwitterMediaReadIntegrationTest.php`, `TwitterUsageControlsTest.php` and the
existing thumbnail/provider regressions. Keep this contract when upstream
eventually supplies equivalent caching behavior.

## Deployment and rollback

Follow the parent frozen playbook, including exact-candidate asset validation,
restored-data rehearsal, drained publishing work, database checkpoint, protected
service identities, and app-only replacement. Update the wrapper and evidence
together: old gates do not contain the new suite. For an override-only release
using the same immutable image, keep the old gate and stage the new record as
`IMAGE_HEX-OVERRIDE_COMMIT.json` alongside it. The guard matches actual files,
not just the image ID or filename. Rollback restores its exact wrapper, two
overrides and matching gate together. Never rewind the live database over later
publishing activity merely to undo this schema-free change.

Verify the installed source/mount hashes, cached previews in authenticated
Analytics, a fake-transport replay against the active runtime, Horizon and the
age-tiered schedule. Provider-faked checks do not establish X billing savings;
actual spending can only be checked once the new X charges take effect.
