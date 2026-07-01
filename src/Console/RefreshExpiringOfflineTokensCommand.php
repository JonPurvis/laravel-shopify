<?php

namespace Osiset\ShopifyApp\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Osiset\ShopifyApp\Messaging\Jobs\RefreshShopOfflineTokenJob;
use Osiset\ShopifyApp\Util;

class RefreshExpiringOfflineTokensCommand extends Command
{
    protected $signature = 'shopify-app:refresh-expiring-offline-tokens
        {--shop= : Renew a single shop by domain (e.g. example.myshopify.com)}
        {--days= : Override renewal window in days (defaults to config)}
        {--dry-run : List shops that would be renewed without dispatching jobs}';

    protected $description = 'Dispatch queue jobs to renew expiring offline tokens before the refresh token expires (optional; requires SHOPIFY_EXPIRING_OFFLINE_TOKENS)';

    public function handle(): int
    {
        if (! Util::getShopifyConfig('expiring_offline_tokens')) {
            $this->error('expiring_offline_tokens is disabled. Set SHOPIFY_EXPIRING_OFFLINE_TOKENS=true first.');

            return self::FAILURE;
        }

        $modelClass = Util::getShopifyConfig('user_model');
        $shopDomain = $this->option('shop');
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) Util::getShopifyConfig('offline_refresh_token_renewal_days');

        $threshold = Carbon::now()->addDays($days);

        $query = $modelClass::query()
            ->whereNotNull('password')
            ->where('password', '!=', '')
            ->whereNotNull('shopify_offline_refresh_token')
            ->where('shopify_offline_refresh_token', '!=', '')
            ->whereNotNull('shopify_offline_refresh_token_expires_at')
            ->where('shopify_offline_refresh_token_expires_at', '<=', $threshold);

        if ($shopDomain) {
            $query->where('name', $shopDomain);
        }

        if ($this->option('dry-run')) {
            $count = 0;
            $query->chunk(100, function ($shops) use (&$count) {
                foreach ($shops as $shop) {
                    $this->line('  - '.$shop->name.' (id: '.$shop->id.')');
                    $count++;
                }
            });

            if ($count === 0) {
                $this->info('No shops need renewal.');
            } else {
                $this->info("Dry run — {$count} shop(s) would be renewed.");
            }

            return self::SUCCESS;
        }

        $dispatched = 0;
        $failed = 0;

        $query->chunk(100, function ($shops) use (&$dispatched, &$failed, $days) {
            foreach ($shops as $shop) {
                try {
                    RefreshShopOfflineTokenJob::dispatch($shop, $days);
                    $dispatched++;
                } catch (\Throwable $e) {
                    $this->warn("  [FAILED] {$shop->name}: {$e->getMessage()}");
                    $failed++;
                }
            }
        });

        if ($dispatched === 0 && $failed === 0) {
            $this->info('No shops need renewal.');

            return self::SUCCESS;
        }

        $this->info("Dispatched {$dispatched} renewal job(s).".($failed > 0 ? " {$failed} shop(s) failed and were skipped." : ''));

        return self::SUCCESS;
    }
}
