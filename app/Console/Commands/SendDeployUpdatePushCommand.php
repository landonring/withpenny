<?php

namespace App\Console\Commands;

use App\Services\Notifications\DeployUpdatePushService;
use Illuminate\Console\Command;

class SendDeployUpdatePushCommand extends Command
{
    protected $signature = 'notifications:deploy-update
        {--deploy-version= : Override deploy version in push payload}
        {--dry-run : Show recipient counts without sending notifications}';

    protected $description = 'Send a Penny deploy update push notification to subscribed users.';

    public function handle(DeployUpdatePushService $service): int
    {
        $version = trim((string) ($this->option('deploy-version') ?: config('pwa.app_version')));
        if ($version === '') {
            $this->error('Missing deploy version. Set PWA_APP_VERSION or pass --deploy-version=...');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $stats = $service->dispatch($version, $dryRun);

        $this->line('Deploy version: '.$version);
        $this->line('Subscriptions: '.$stats['subscriptions']);

        if ($dryRun) {
            $this->info('Dry run complete. No push notifications were sent.');
            return self::SUCCESS;
        }

        if ($stats['skipped']) {
            $this->warn('Push dispatch skipped: '.($stats['reason'] ?? 'Unknown reason.'));
            return self::SUCCESS;
        }

        $this->line('Sent: '.$stats['sent']);
        $this->line('Failed: '.$stats['failed']);
        $this->line('Expired removed: '.$stats['expired']);
        $this->info('Deploy update push dispatch completed.');

        return self::SUCCESS;
    }
}
