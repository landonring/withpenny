<?php

namespace App\Console\Commands;

use App\Services\Notifications\DeployUpdatePushService;
use Illuminate\Console\Command;

class SendDeployUpdatePushCommand extends Command
{
    protected $signature = 'notifications:deploy-update
        {--deploy-version= : Override deploy version in push payload}
        {--dry-run : Show recipient counts without sending notifications}';

    protected $description = 'Send Penny deploy update notifications (in-app + push where available).';

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
        $this->line('Users checked: '.$stats['users']);
        $this->line('Eligible: '.$stats['eligible']);
        $this->line('Skipped (already on version): '.$stats['skipped_version']);
        $this->line('Skipped (24h cooldown): '.$stats['skipped_rate']);

        if ($dryRun) {
            $this->info('Dry run complete. No notifications were sent.');
            return self::SUCCESS;
        }

        $this->line('Notifications stored: '.$stats['sent']);
        $this->line('Push sent: '.$stats['push_sent']);
        $this->line('Push failed: '.$stats['push_failed']);
        $this->info('Deploy update notification dispatch completed.');

        return self::SUCCESS;
    }
}
