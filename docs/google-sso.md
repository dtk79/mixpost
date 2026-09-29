# Google sign-in for Mixpost

Google sign-in is an active **production configuration**, not a source-code or bind-mounted override. Mixpost Pro 7.0.1 supplies the OIDC flow and login button. Preserve the settings in the application database through each upgrade; do not put the OAuth client secret in Git.

## Current configuration (verified 2026-09-29)

- Mixpost: [Admin Console → Settings → Single Sign-On](https://mixpost.peachyhq.com/mixpost/admin/configs/sso).
- Enable single sign-on: on. Identity provider: Generic OIDC. Issuer: `https://accounts.google.com`.
- Google Cloud project: `Mixpost` (`gen-lang-client-0534053021`); OAuth web client: `Peachy Mixpost SSO`. Its authorized redirect URI is `https://mixpost.peachyhq.com/mixpost/auth/sso/callback`.
- Scopes: `openid profile email`. Login button label: `Google`.
- Require a verified email: on. Allow password login: on.
- New users: **Only sign in existing users**. Automatic account creation and group/role mapping are off. Workspace membership and administrator status remain managed in Mixpost.

On first Google sign-in, Mixpost matches the verified Google email to an existing Mixpost user email and links the Google identity to that user. Subsequent sign-ins use the linked Google subject. A person without a matching Mixpost record is rejected; create and assign their Mixpost user before they try Google sign-in. This configuration does not restrict Google sign-in exclusively to `ducatix.com`: another existing Mixpost user with a matching verified Google address can also use it. Do not describe it as a domain allowlist.

The six existing `@ducatix.com` Mixpost user records were checked on 2026-09-29. Kirk's record was normalized from `Kirk@ducatix.com` to `kirk@ducatix.com` to ensure reliable first-login matching. No account, role, or workspace was added as part of this setup.

The Google OAuth app is External and currently has a **Testing** publishing status. Because it requests only basic identity scopes, [Google permits users outside the test-user list to authorize it](https://support.google.com/cloud/answer/15549945?hl=en); a non-listed account reached Google's normal consent screen on 2026-09-29. If scopes change, re-evaluate the Google audience restrictions before rollout. Publishing the app to Production requires company-approved public homepage, privacy-policy, and terms links on an authorized domain; do not invent those URLs or upload a logo just to bypass branding requirements.

## Upgrade and acceptance checks

1. Before an upgrade, back up the application database and record these settings without exporting the client secret into the repository. Confirm the OAuth redirect URI still matches the Google Cloud client.
2. After an upgrade, reopen the Single Sign-On settings and verify provider, issuer, scopes, button label, verified-email requirement, password-login setting, existing-user provisioning, and blank groups claim. Use Mixpost's **Test connection** control.
3. Open [the unauthenticated login page](https://mixpost.peachyhq.com/mixpost/login) and confirm **Sign in with Google** is visible alongside password login. Verify its redirect reaches the configured Google OAuth client and callback.
4. Complete an end-to-end login with an authorized existing Ducati X user. Confirm it returns to the same Mixpost user, existing workspaces, and existing role. Dan's account passed this check during the initial setup; do not claim every team member has personally signed in.
5. For a newly added user, verify their Google email and Mixpost user email agree before first login. Keep automatic account creation off unless a separately reviewed provisioning policy is adopted.

[Mixpost's SSO documentation](https://docs.mixpost.app/services/sso/) describes existing-user email matching, permanent identity linking, and the meaning of each setting. Password login remains available, so offboarding must also disable or remove the Mixpost account when access should end.
