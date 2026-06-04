<?php

use Illuminate\Support\Facades\Queue;
use Inovector\Mixpost\Commands\ImportAccountData;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\SocialProviders\Mastodon\Jobs\ImportMastodonPostsJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportFacebookInsightsJob;
use Inovector\Mixpost\SocialProviders\Twitter\Jobs\ImportTwitterPostsJob;

it('can import account data for selected providers', function () {
    Queue::fake();

    Account::factory()->state(['provider' => 'twitter'])->create();
    Account::factory()->state(['provider' => 'facebook_page'])->create();
    Account::factory()->state(['provider' => 'mastodon'])->create();

    $this->artisan(ImportAccountData::class, [
        '--providers' => 'facebook_page,mastodon',
    ])->assertExitCode(0);

    Queue::assertNotPushed(ImportTwitterPostsJob::class);
    Queue::assertPushed(ImportFacebookInsightsJob::class, 1);
    Queue::assertPushed(ImportMastodonPostsJob::class, 1);
});
