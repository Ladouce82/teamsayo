<?php

namespace App\Console\Commands;

use App\Services\Billing;
use App\Services\PayPal;
use Illuminate\Console\Command;
use Throwable;

class PaypalSetup extends Command
{
    protected $signature = 'teamsayo:paypal-setup';
    protected $description = 'Create the PayPal product, subscription plans and webhook (safe to run repeatedly).';

    public function handle(PayPal $paypal, Billing $billing): int
    {
        if (! $paypal->configured()) {
            $this->warn('PayPal credentials are not set; skipping.');

            return self::SUCCESS;
        }
        try {
            $r = $billing->setup(route('webhooks.paypal'));
            $this->info('PayPal ready: ' . count($r['plans']) . ' plans, webhook ' . $r['webhook']);
        } catch (Throwable $e) {
            $this->error('PayPal setup failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
