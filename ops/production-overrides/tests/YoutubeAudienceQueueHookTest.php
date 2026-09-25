<?php

// Upstream scheduling contract doubles: the provider hook must retain existing low-priority jobs.
namespace Inovector\Mixpost\Abstracts { abstract class SocialProvider {} }
namespace Inovector\Mixpost\Concerns\OAuth { trait RefreshesAccessToken {} }
namespace Inovector\Mixpost\SocialProviders\Google\Concerns {
    trait ManagesOAuth {}
    trait ManagesYoutubeAccountDeletion {}
    trait ManagesYoutubeResources {}
    trait UsesResponseBuilder {}
    trait ManagesYoutubeJobs {
        public static function lowPriorityJobs(): array { return ['upstream-job']; }
    }
}
namespace {
    require dirname(__DIR__).'/YoutubeProvider.php';
    $jobs = \Inovector\Mixpost\SocialProviders\Google\YoutubeProvider::lowPriorityJobs();
    if ($jobs !== ['upstream-job', \Inovector\Mixpost\Jobs\DispatchYoutubeAudienceSnapshotsJob::class]) {
        throw new \RuntimeException('YouTube schedule hook must preserve upstream jobs and add dispatcher once.');
    }
    $manifest = json_decode(file_get_contents(dirname(__DIR__).'/deployment-manifest.json'), true);
    $hosts = array_column($manifest['overrides'], 'host');
    if (in_array('Schedule.php', $hosts, true)) {
        throw new \RuntimeException('YouTube must not activate unrelated global scheduler changes.');
    }
    foreach (['CollectYoutubeAudienceJob.php', 'DispatchYoutubeAudienceSnapshotsJob.php', 'YoutubeAudienceCollector.php', 'YoutubeAudienceSnapshot.php', '2026_09_25_210000_create_youtube_audience_reports.php'] as $file) {
        if (count(array_filter($hosts, fn ($host) => $host === $file)) !== 1) {
            throw new \RuntimeException('Required managed file missing or duplicated: '.$file);
        }
    }
    echo "YouTube queue hook: 7 checks passed\n";
}
