# Code discovery

Prefer codebase-memory-mcp graph discovery and snippets. Index the repository
first when it is not indexed. Use text/file searches for config, shell scripts,
error strings, or when the graph is insufficient.

# Frozen production releases

Follow `docs/mixpost-update-playbook.md` and `docs/frozen-release-gates.md`.
Production Pro source differs from this repository's Lite package. Test the
exact frozen candidate and its compiled assets using the release validator.
Do not waive required behavior tests, remove the mounted startup guard, revive
the archived 6.2.0 image, use the generic/latest updater, or mark a safeguard
superseded based only on release notes. An upstream implementation must pass
the same failure/recovery contract on each candidate.

Preserve both effective Compose files, mounted customizations, MySQL, Redis,
credentials, volumes, and networks. Rehearse against isolated restored data;
checkpoint before cutover and retain database-aware rollback evidence. Recreate
only the app. Never use `docker compose down -v`. Report source, CI, rehearsal,
cutover, storage verification, and authenticated browser proof separately.
