# Instagram Standalone OAuth Runbook

This document is the authoritative reference for the Peachy HQ Mixpost `instagram_standalone` integration: which Meta app it runs on, how the credentials and webhooks are wired, and the exact procedure for connecting a new Instagram account. Last verified 2026-08-14 via the Meta Developer Tools MCP and the Meta App Dashboard.

For Instagram accounts connected through a Facebook Page (`instagram` provider), use the Facebook service instead — that is a different flow, a different credential pair, and a different redirect URI. See [Mixpost's Facebook & Instagram guide](https://docs.mixpost.app/services/social/facebook/).

## App Identities

There are two ID namespaces, and confusing them cost us a full audit:

- A **Meta App ID** identifies the parent app in the developer portal (`developers.facebook.com/apps`).
- An **Instagram App ID** identifies the Instagram app nested *inside* a Meta app, under **Use cases -> Customize -> API setup with Instagram login**. It never appears in the portal's app list, it is not queryable as an app via the Graph API or DevTools MCP, and it is the `client_id` used for standalone Instagram OAuth. Mixpost's Instagram service form takes this ID, not the Meta App ID.

### Active

| Field | Value |
| --- | --- |
| Meta app | **Peachy MixPost** (`25227226140295903`) |
| Nested Instagram app | **Peachy MixPost - IG** |
| Instagram App ID (Mixpost `client_id`) | **`1224023842978103`** |
| Meta app mode | Live, business verification passed |
| Base domain | `mixpost.peachyhq.com` |
| Data deletion URL | `https://mixpost.peachyhq.com/pages/data-deletion` |

### Deprecated — do not use

| Field | Value |
| --- | --- |
| Meta apps | **Peachy Posting** (`1680199579801664`, dev mode) and **Peachy Posting** (`1475154360824857`, dev mode, unconfigured) |
| Nested Instagram app | **Peachy Posting-IG**, Instagram App ID `2401015500399944` |
| Why deprecated | Parent app never left dev mode, webhook callback/verify token never configured (webhooks require a published app), app review never completed. Mixpost pointed here until 2026-08-14. |

If `2401015500399944` shows up anywhere (old notes, the Mixpost form, support threads), it is the retired Instagram App ID.

## Mixpost Configuration Reference

Location: Mixpost **User Menu -> Admin Console -> Services -> Instagram** (not the Facebook form).

- **App ID**: `1224023842978103` (the Instagram App ID above).
- **App secret**: from the same portal panel (**Instagram app secret -> Show**). Also validates incoming webhook signatures, so it must match the app that sends the webhooks.
- **Status**: Active.
- **Enable Webhooks**: on. The **Webhook Verify Token** must byte-for-byte match the verify token registered in the Meta webhook configuration (below).

URLs derived from `APP_URL` + `MIXPOST_CORE_PATH` (`mixpost`):

```text
OAuth redirect:   https://mixpost.peachyhq.com/mixpost/callback/instagram_standalone
Webhook callback: https://mixpost.peachyhq.com/mixpost/inbox-webhook/instagram_standalone
```

## Meta Portal Configuration Reference

All inside **Peachy MixPost -> Use cases -> Manage messaging & content on Instagram -> Customize**:

- **API setup with Instagram login -> 4. Set up Instagram business login -> Business login settings**: the OAuth redirect URL above must be listed exactly — `https`, no trailing slash, no `www`. A bare `https://mixpost.peachyhq.com` entry does **not** cover it (this exact gap is why OAuth silently failed against this app before 2026-08-14).
- **Webhooks**: callback URL above, verify token matching the Mixpost form, subscribed fields `comments` and `mentions`.
- **Permissions**: `instagram_business_basic`, `instagram_business_content_publish`, `instagram_business_manage_comments`, `instagram_business_manage_insights`.

### Access level

The app currently has **Standard Access only** — App Review for the four permissions has not been approved (all were rejected in a prior submission, and a stuck submission blocks resubmitting). Consequences:

- **Only Instagram accounts holding a role on the app can connect.** Every new account must be added as an Instagram Tester first (procedure below).
- Serving arbitrary third-party accounts would require completing App Review with Advanced Access. Screencast evidence was the missing step at last check; Mixpost publishes ready-made permission descriptions in its [Instagram App Review guide](https://docs.mixpost.app/services/social/instagram/app-review).

## Adding a New Instagram Account

Prerequisite: the Instagram account must be **Professional** (Business or Creator). Personal accounts cannot connect.

1. **Grant the tester role.** Meta portal -> **Peachy MixPost** (`25227226140295903`) -> **App roles -> Roles -> Add people -> Instagram Tester** -> enter the Instagram username -> send invite.
2. **Accept the invite as the account.** Log in to that Instagram account at instagram.com -> **Settings -> Apps and websites -> Tester invites** -> accept.
3. **Connect in Mixpost.** Workspace -> **Social Accounts -> Add Account -> Instagram** -> log in as the account in the authorization window and accept **all** requested permissions.
4. **Verify.**
   - The account appears in Mixpost's Social Accounts.
   - The account appears in the portal under **API setup with Instagram login -> 2. Generate access tokens**, with its **Webhook Subscription toggle On** (Mixpost subscribes accounts at connect time).
   - Publish a test post from Mixpost and confirm it reaches Instagram.
   - Have another account comment on a post and confirm it arrives in Mixpost's Engagement inbox (proves webhook delivery and signature validation).

## Troubleshooting

- **OAuth error about the redirect URI**: the redirect URL is missing from or mistyped in Business login settings. Exact-match rules apply.
- **"Invalid platform app" during authorization**: the App ID in the Mixpost Instagram form is not a valid Instagram App ID (wrong namespace or retired app).
- **Publishing works but Engagement is empty for one account**: the account's webhook toggle is Off, or the account connected before webhooks were enabled. Reconnect the account in Mixpost — subscription only happens at connect time.
- **All webhooks rejected**: app secret in the Mixpost form doesn't match the app sending the events (signature validation fails). Ensure form credentials and the webhook-sending app are the same app.
- **Existing accounts keep working after a credential change**: expected. Long-lived Instagram tokens refresh without the client credentials, so a wrong App ID in the form breaks only *new* connections — which is how the pre-2026-08-14 mismatch went unnoticed.

## Migration History

### 2026-08-14 — Consolidated onto Peachy MixPost - IG

Previously Mixpost's Instagram form held `2401015500399944` (Peachy Posting-IG): publishing worked for role accounts, but Engagement webhooks were impossible (dev-mode parent, no webhook config), and a parallel Instagram app (Peachy MixPost - IG) held a second set of account authorizations. Changes made:

1. Added `https://mixpost.peachyhq.com/mixpost/callback/instagram_standalone` to Peachy MixPost - IG's Business login settings (only the bare domain was listed).
2. Added missing accounts as Instagram Testers on Peachy MixPost.
3. Verified webhook callback + verify token on Peachy MixPost.
4. Switched the Mixpost Instagram form to `1224023842978103` + its secret.
5. Reconnected `dtastical` in Mixpost (new token issued by the active app).
6. **Pending: reconnect `thedriftersseries`** (existing token still publishes via the old app until then).
7. Pending cleanup: remove accounts from Peachy Posting-IG and deactivate both Peachy Posting Meta apps once all accounts are reconnected.
