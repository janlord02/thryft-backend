<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webpush:vapid';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate VAPID keys for WebPush notifications';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Generating VAPID keys...');

        $keys = VAPID::createVapidKeys();

        $this->info('VAPID keys generated successfully!');
        $this->newLine();
        $this->info('Add these to your .env file:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY=' . $keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY=' . $keys['privateKey']);
        $this->line('VAPID_SUBJECT=' . config('app.url'));
        $this->newLine();
        $this->info('Make sure to keep your private key secure!');

        return Command::SUCCESS;
    }
}
