# Video display proportions

Some portrait videos store a landscape or nearly square pixel grid and carry
non-square sample aspect ratio (SAR) metadata. Players honor it; JPEG images
and CSS boxes need the displayed proportions baked into their dimensions.

The October 12 original is HEVC, 2156 by 2160, SAR 9:16, DAR 539:960.
The previously observed uploaded MP4 was 1920 by 1080, SAR 1617:5120,
with the same portrait DAR. Playback was correct but its thumbnail and saved
card dimensions were landscape.

`VideoProbe.php` accounts for SAR, falls back to a valid DAR when SAR is unknown,
and applies rotation after correcting pixel shape. Invalid/zero ratios safely
fall back to the encoded dimensions. `MediaVideoThumbConversion.php` uses the
display ratio during FFmpeg frame extraction, scales inside the existing
430 by 430 thumbnail bounds, and writes square pixels. The first-frame fallback
uses the same filter. The source video is not rewritten by this fix.

Both files are frozen-image overrides, registered in the modification inventory
and deployment manifest. Preserve the current Pro package, full assets, all
existing mounts and both Compose files. Run the frozen upload gate and
preservation check on every exact candidate. Then run
`ops/production-overrides/tests/VideoDisplayAspectRatioTest.php` against that
candidate without a network or production data. It covers square-pixel and
anamorphic videos, missing/invalid ratios, DAR fallback, rotation metadata,
bounded JPEG extraction, and optionally the original using
`VIDEO_ASPECT_ORIGINAL=/fixture/original.mp4`.

Rehearse on an isolated restored database with production storage, workers,
mail, and providers disabled before app-only activation. Check the actual upload
pipeline in that rehearsal. Verify authenticated library rendering and playback
after activation. A user's fresh re-upload remains its own acceptance check.

`VideoDisplayUploadIntegrationTest.php` exercises the original at
`/fixture/original.mp4` through chunked acceptance, the real conversion job,
thumbnail storage, and the library resource on the isolated restored database.
It requires a writable `/audit` for its result and corrected thumbnail. It also
asserts that the stored original retains its exact source hash.

Existing media retain their old thumbnail/dimensions until explicitly repaired
or re-uploaded. Do not rewrite existing user media as part of rollout. Roll back
to the previous tested frozen image and its gate, retaining current database and
media writes; this patch adds no schema migrations.
