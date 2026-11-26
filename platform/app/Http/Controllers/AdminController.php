<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Services\Billing;
use Illuminate\View\View;

/** TeamSayo staff console: customers, revenue and system health. Never shows customers' app data. */
class AdminController extends Controller
{
    public function index(): View
    {
        $workspaces = Workspace::with('owner:id,name,email')->withCount('memberships')->latest()->limit(200)->get();
        $mrr = $workspaces->where('status', 'active')->sum(function ($w) {
            $unit = Billing::unitPrice($w->plan === 'trial' ? 'team' : $w->plan, $w->cycle ?: 'monthly', (bool) $w->nonprofit);

            return $w->cycle === 'annual' ? $unit * $w->seats / 12 : $unit * $w->seats;
        });

        return view('admin', [
            'workspaces' => $workspaces,
            'mrr' => $mrr,
            'users' => User::count(),
            'signups' => User::where('created_at', '>', now()->subDays(30))->count(),
            'payments' => Payment::with('workspace:id,name')->latest('paid_at')->limit(20)->get(),
            'webhookErrors' => WebhookEvent::whereNotNull('error')->latest()->limit(20)->get(),
        ]);
    }
}
