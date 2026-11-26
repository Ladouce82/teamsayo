<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class Maintenance extends Command
{
    protected $signature = 'teamsayo:maintenance';
    protected $description = 'Expire ended trials, purge workspaces deleted over 30 days ago and trim old notifications and logs.';

    public function handle(): int
    {
        $expired = Workspace::where('status', 'trialing')->where('trial_ends_at', '<', now())->update(['status' => 'expired']);
        // Cancelled subscriptions keep access until the paid period ends.
        $ended = 0;
        foreach (Subscription::where('status', 'cancelled')->where('current_period_end', '<', now())->pluck('workspace_id')->unique() as $wsId) {
            $hasActive = Subscription::where('workspace_id', $wsId)->where('status', 'active')->exists();
            if (! $hasActive) {
                $ended += Workspace::whereKey($wsId)->where('status', 'active')->update(['status' => 'cancelled']);
            }
        }
        // Ended trials and cancelled subscriptions stay read-only for 90 days, then the workspace is deleted (recoverable for 30 more).
        $retired = 0;
        foreach (Workspace::whereIn('status', ['expired', 'cancelled'])->where('updated_at', '<', now()->subDays(90))->get() as $ws) {
            $ws->delete();
            $retired++;
        }
        $purged = 0;
        foreach (Workspace::onlyTrashed()->where('deleted_at', '<', now()->subDays(30))->get() as $ws) {
            Storage::disk('local')->deleteDirectory("workspaces/{$ws->id}");
            $ws->forceDelete();
            $purged++;
        }
        AppNotification::where('created_at', '<', now()->subDays(180))->delete();
        AuditLog::where('created_at', '<', now()->subMonths(24))->delete();
        $this->info("Trials expired: {$expired}; subscriptions ended: {$ended}; retired: {$retired}; purged: {$purged}");

        return self::SUCCESS;
    }
}
