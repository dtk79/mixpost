# Mixpost customizations and update hardening

This is the entry point for maintaining our customized Mixpost installation.
An update is accepted only when required behavior is demonstrated against its
exact licensed Pro source, compiled assets, mounted overrides, and runtime.
Release notes and a newer version number cannot retire a modification.

## Records and authority

| Record | Purpose |
| --- | --- |
| [Modification register](modifications.json) | Every tracked production override, its destination, and review obligations |
| [Instance alterations](../mixpost-instance-alterations.md) | Behavior, incidents, historical decisions, and feature-specific checks |
| [Deployment manifest](../../ops/production-overrides/deployment-manifest.json) | Exact source-to-host-to-container mapping for mounted files |
| [Update and hardening checklist](update-hardening.md) | Required preservation, regression, rehearsal, cutover, and follow-up steps |
| [October 10 production release](local-upload-release-2026-10-10.md) | Exact image, live library/composer timings, delivery proof, and rollback checkpoint |
| [Local uploads](local-uploads.md) | Persistent upload acceptance, queued delivery, readiness, recovery, and capacity |
| [Upload transfer progress](upload-transfer-progress.md) | Sent-byte progress, confirmation phases, slow-ingress evidence, and tested follow-up candidate |
| [Frozen release gates](../frozen-release-gates.md) | Enforced exact-candidate upload/startup contract |
| [Frozen update playbook](../mixpost-update-playbook.md) | Operational release and database-aware rollback procedure |
| [Upload incident investigation](../upload-storage-latency-2026-10-10.md) | October 10 provider-delay evidence and diagnostic limits |

The live `/root/mixpost/frozen-release.json` and its private checkpoint directory
identify what is running. Git, CI, rehearsal, deployment, and browser verification
are separate states. The source mirror in this repository is Lite; testing it
does not prove compatibility with the deployed licensed Pro package.

The generated `DEPLOY.md` still describes the former dashboard vendor updater.
That workflow is disabled for frozen releases; the frozen playbook and gates
above take precedence. Do not reactivate it or use a generic/latest image pull.
The asset-build workflow cancels superseded runs and no longer force-pushes main;
a build must not overwrite a newer modification commit.

Use a complete reviewed checkout for release tooling. The package's Git export
rules omit `docs/`, so a default Git archive lacks the preservation records.
If copying tooling to the release host, include its registered sources and docs,
and run the register check there before issuing or promoting candidate evidence.

## Required maintenance

For every modification, record the problem, expected behavior, implementation
files, regression checks, live acceptance, rollback, and retirement conditions.
Update the register and deployment manifest in the same change. New image-baked
changes need their own patch/build recipe and explicit fingerprints too.

For every incident, preserve a sanitized incident report and private raw evidence.
Turn the confirmed failure into a regression check when practical. Record which
layer failed: browser transport, app permissions, storage, queue, conversion,
publishing, or provider acknowledgment. A healthy login alone is insufficient.

The automated register check detects undocumented/missing override files and
mapping drift. The existing release gate fingerprints all effective read-only
mounts and the upload source/build; focused behavior gates verify what fingerprints
cannot. Manual live checks remain explicit where a provider cannot be tested
without publishing. No test post, email, or support message is implied.
