# YouTube viewer demographics acquisition

This is a prepared collector and OAuth change, not an active production feed. No background job or provider collection runs when an Annex page is opened.

## Verified September 25, 2026

The Off Brand YouTube connection is Mixpost account **97**, workspace **25**, channel `UCG35kFqmv3Ka8MbXZqTKAMw`. The account is authorized and its current token is valid. A read-only Google token inspection confirms only `youtube` and `youtube.upload` permissions. It does **not** grant `yt-analytics.readonly`; analytics retrieval cannot proceed until channel-owner consent adds it. The live collector output is captured in `youtube-audience-probe-2026-09-25.json`. No tokens were printed or copied, no refresh or account writes occurred, and the collector sent no Analytics reports requests once it detected the missing permission.

The installed provider's `getScopes()` requests only the two publishing scopes, and its shared Google OAuth handler does not preserve returned scope metadata. The exporter verifies current granted scopes using Google's tokeninfo endpoint rather than guessing from provider configuration. The separate `YoutubeProvider.php` managed override adds analytics read access while retaining the existing publishing scopes. It was copied from the installed provider on this date; rebase this whole-file override if Mixpost's upstream provider changes.

## Prepared implementation

- `ops/production-overrides/YoutubeAudienceReport.php`: reusable transport-injected collector; fixed Google report endpoint, explicit channel ID, per-report availability, sanitized errors, schema validation.
- `ops/production-overrides/YoutubeProvider.php`: adds only `yt-analytics.readonly` to the current requested scopes. No monetary permission requested.
- `ops/production-overrides/deployment-manifest.json`: both files registered as managed read-only mounts for a later authorized deployment.
- `ops/scripts/export-youtube-audience.php`: scoped account resolution and current-token verification inside the Mixpost application; no database writes, refreshes, schedule dispatch, or account mutation.
- `ops/scripts/export-youtube-audience.py`: operator entry point; streams the above source into the existing container, avoiding installation and credential copying.

Run from this repository:

```sh
python3 ops/scripts/export-youtube-audience.py 25 97 2026-09-01 2026-09-25
```

The first two arguments are required workspace and account IDs. Optional `--subscription subscribed` or `--subscription unsubscribed` filters **viewers at the time of viewing**, not the full subscriber population. Default is all viewers. Exit 0 = available or no reportable data; exit 2 = partial/unavailable/authorization required or expired token. Exit 64 = invalid input; 1 = sanitized unexpected failure. SSH-level failures retain the SSH exit code. An expired access token is reported without refreshing it; the normal Mixpost provider refresh or reconnection must run before another export.

## Contract for Annex

The JSON envelope contains `schemaVersion:1`, `provider:youtube`, `accountId`, `workspaceId`, `channelId`, `audienceType:viewers`, `subscription`, `period:{start,end,timeZone:America/Los_Angeles}`, `fetchedAt`, `dataThrough:null`, and `reports`. The acquisition timestamp must not be presented as the latest fully reported date.

Reports:

| Key | Dimension | Metric/unit | Meaning |
| --- | --- | --- | --- |
| age | ageGroup | viewerPercentage / percent | Provider percentage of logged-in viewers |
| gender | gender | viewerPercentage / percent | Provider percentage of logged-in viewers |
| country | country | views / views | Video views in each reported country, not unique viewers |

Each report has `status`, `dimension`, `metric`, `unit`, `population`, `buckets:[{key,value}]`. Available reports also include `reportedTotal` and `denominator`. Age/gender values are preserved as provider percentages, even when their sum is below 100; no people counts are inferred. Country shares, if displayed, must divide by the sum of **all returned country view counts**, retain `ZZ` as unknown, and say “share of reported views.” Country distributions may be available while age/gender is privacy-limited.

States are `available`, `no_reportable_data`, `authorization_required`, or `unavailable`; mixed report states produce envelope `partial`. Empty successful reports use reason `empty_or_privacy_limited`: the API does not identify whether suppression or no reportable activity caused an empty result. They must not be shown as zero demographics. A missing scope yields envelope `authorization_required`, `requiredScope`, and empty `reports` without sending analytics queries. API disabled, access denied, provider error, transport error, and malformed response are separately represented by safe reason codes.

Keep percentages channel-specific. Never sum/average YouTube demographic percentages across channels without compatible population denominators, derive counts using subscribers/views, or merge them into Instagram follower buckets. The collector retains the 13–17 age group. An adult-only view must explicitly document its denominator rather than silently treating original provider shares as adult-only percentages.

## Remaining activation steps

1. Review and deploy the managed provider scope change through the existing Infra Mixpost override process. Preserve the other manifest entries and upstream provider behavior.
2. Enable **YouTube Analytics API** in the Google Cloud project used by the Mixpost Google service, and add the read-only analytics scope to its consent-screen configuration if required. Current API enablement was not checked because the missing scope already blocks this request.
3. Have the channel owner reconnect the existing Off Brand account in Mixpost and approve the new analytics permission. Do not create a duplicate account, revoke the existing grant, or remove publishing scopes.
4. Repeat the read-only export; verify both scope and returned report availability. A successful consent does not guarantee age/gender rows: Google can suppress small samples.
5. Add a reviewed persistence/scheduling adapter and a scoped Annex read endpoint for this separate `viewers` contract. **This change does not yet schedule collection, persist snapshots, or wire YouTube charts into Annex.** Store exact requested ranges and acquisition time; do not reuse another period as if it matched a requested day/week/month. Annex must reapply its workspace/account allowlist when ingesting/reading exports, never expose this operator command as an arbitrary-account public route.

Neither code deployment nor owner reconnection was performed during this work. Existing publishing, tokens, database records, and queues are unchanged.

## Validation

```sh
php ops/production-overrides/tests/YoutubeAudienceReportTest.php
php -l ops/production-overrides/YoutubeAudienceReport.php
php -l ops/production-overrides/YoutubeProvider.php
php -l ops/scripts/export-youtube-audience.php
```

32 focused assertions cover scope gating/no provider calls, explicit channel selection, viewer/subscriber isolation, header order, count/percentage semantics, privacy empty state, partial geography, invalid responses/dates, safe errors, and managed scope registration. The exporter was exercised inside the live container without installing files and correctly returned the missing-permission state.

Primary references: [Channel report types and authorization](https://developers.google.com/youtube/analytics/channel_reports), [Query endpoint](https://developers.google.com/youtube/analytics/reference/reports/query), [Dimensions and subscribed status](https://developers.google.com/youtube/analytics/dimensions), [Anonymization](https://developers.google.com/youtube/analytics/data_model#data-anonymization).
