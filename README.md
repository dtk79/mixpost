[<img src="./art/standwithua.png" alt="Stand with Ukraine" />](https://supportukrainenow.org)

* * *

[<img src="./art/page-cover.png" alt="Mixpost cover" />](https://mixpost.app)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/inovector/mixpost.svg?style=flat-square)](https://packagist.org/packages/inovector/mixpost)
[![Tests](https://img.shields.io/github/actions/workflow/status/inovector/mixpost/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/inovector/mixpost/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/inovector/mixpost.svg?style=flat-square)](https://packagist.org/packages/inovector/mixpost)

## Introduction

Mixpost Lite is a self-hosted social media management package for Laravel. It adds a `/mixpost` Inertia/Vue application for connecting social accounts, composing posts, scheduling publishing, managing media, reviewing reports, and maintaining system logs/status.

This repository contains the Lite edition. Mixpost Pro and Enterprise are commercial editions with additional features and support options.

## Features

- Social account management for supported providers.
- Post composer with per-provider versions, media limits, validation, scheduling, and duplication.
- Calendar and queue-oriented views for scheduled content.
- Media library with uploads, external media downloads, GIF search, image resizing, and video thumbnail support.
- Analytics imports, audience imports, and metric processing jobs for providers that expose reporting APIs.
- System status and system log pages.
- Profile, password, service, tag, and application settings management.
- Optional Google Analytics 4 page-view tracking for the Mixpost UI.

## Supported Providers

The Lite codebase currently includes providers for:

- X/Twitter
- Facebook Pages
- Mastodon

Configuration also includes media and validation limits for Facebook Groups, and the scheduler has cost-aware provider groups used by the wider Mixpost runtime. Provider availability still depends on the edition, configured services, and the APIs available to your application.

## Requirements

- PHP 8.2 or newer
- Laravel 10.47, 11, or 12
- Redis, required by Laravel Horizon and the test suite
- A supported database for Laravel; CI runs against MySQL
- Node.js 20 for building frontend assets
- FFmpeg and FFprobe when video thumbnails should be generated

Composer package requirements also include the `fileinfo` PHP extension.

## Installation

The full installation guide is maintained at [docs.mixpost.app/lite](https://docs.mixpost.app/lite/).

For a Laravel application that already meets the requirements, the package entry point is:

```bash
composer require inovector/mixpost
php artisan mixpost:install
```

The installer publishes Mixpost assets, publishes migrations, optionally runs migrations, and reports the Mixpost UI URL. By default the UI is available at:

```text
{APP_URL}/mixpost
```

## Configuration

Publish and review the package config before production use:

```bash
php artisan vendor:publish --tag=mixpost-config
```

Important environment variables:

```dotenv
MIXPOST_AUTH_GUARD=web
MIXPOST_DISK=public
MIXPOST_CACHE_PREFIX=mixpost
MIXPOST_LOG_CHANNEL=
MIXPOST_GOOGLE_ANALYTICS_ID=
FFMPEG_PATH=/usr/bin/ffmpeg
FFPROBE_PATH=/usr/bin/ffprobe
```

`MIXPOST_GOOGLE_ANALYTICS_ID` is optional. Leave it empty to disable analytics tracking in the Mixpost UI.

Access to Mixpost is controlled by Laravel's `viewMixpost` gate. The package defines an allow-all default gate, so host applications should override that gate when Mixpost access needs to be restricted.

## Scheduling And Queues

Mixpost publishes and imports through Artisan commands and queued jobs. Add Mixpost's schedule registration from your Laravel console kernel or scheduling bootstrap:

```php
\Inovector\Mixpost\Schedule::register($schedule);
```

The schedule runs post publishing every minute, imports provider data and audience metrics on recurring intervals, processes metrics, deletes old provider data daily, and prunes the temporary media directory hourly.

Run a queue worker or Horizon in production so account imports, publishing, media work, and notifications can be processed.

## Artisan Commands

| Command | Purpose |
| --- | --- |
| `mixpost:publish-assets {--force=}` | Publish compiled Mixpost assets to the host application's public directory. |
| `mixpost:run-scheduled-posts` | Scan due scheduled posts and dispatch publishing. |
| `mixpost:import-account-data {--accounts=} {--providers=}` | Import provider data such as posts or insights. |
| `mixpost:import-account-audience {--accounts=} {--providers=}` | Import audience counts such as followers or fans. |
| `mixpost:process-metrics {--accounts=} {--providers=}` | Process imported metrics for reporting. |
| `mixpost:delete-old-data` | Delete old data imported from social service providers. |
| `mixpost:prune-temporary-directory {--hours=2}` | Remove old temporary media files. |
| `mixpost:migrate-media-storage {--from=} {--to=} {--before=} {--delete-source} {--dry-run}` | Migrate media records and files between Laravel filesystem disks. |
| `mixpost:create-mastodon-app {server}` | Create a Mastodon application for a server. |
| `mixpost:clear-settings-cache` | Clear cached Mixpost settings. |
| `mixpost:clear-services-cache` | Clear cached social service configuration. |

`--accounts` and `--providers` accept comma-separated filters.

## Local Development

Install dependencies:

```bash
composer install
npm ci
```

Run tests:

```bash
composer test
```

Run static analysis and formatting:

```bash
composer analyse
composer format
```

Build frontend assets:

```bash
npm run build
```

During frontend work you can run Vite with:

```bash
npm run dev
```

CI tests PHP 8.2 and 8.3 against Laravel 10, 11, and 12.

## Changelog

Please see [Releases](../../releases) and [CHANGELOG.md](CHANGELOG.md) for recent changes.

## Contributing

This repository contains the Lite edition of Mixpost, related to the commercial [Mixpost](https://mixpost.app/) product. Please open an issue before starting feature work so the scope can be discussed first. Pull requests for optimizations and bug fixes are welcome.

When contributing:

- Keep Lite edition features distinct from Mixpost Pro and Enterprise features.
- Use clear commit messages and pull request descriptions.
- Follow the [PSR-12 Coding Standard](https://www.php-fig.org/psr/psr-12/).
- Imitate the existing Mixpost code style and test changes that alter behavior.

## Security Vulnerabilities

Please review [our security policy](SECURITY.md) for how to report security vulnerabilities.

## Community

- [Discord](https://mixpost.app/discord)
- [Facebook Private Group](https://www.facebook.com/groups/getmixpost)

[<img src="./art/demo.png" alt="Mixpost demo" />](https://mixpost.app)

## Credits

- [Dima Botezatu](https://github.com/lao9s)
- [All Contributors](../../contributors)

## License

Mixpost is licensed under the [MIT License](LICENSE.md), sponsored and supported by [Inovector](https://inovector.com).
