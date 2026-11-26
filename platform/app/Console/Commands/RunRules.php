<?php

namespace App\Console\Commands;

use App\Http\Controllers\ApiController;
use App\Models\AppItem;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Server-side automation: reminders, escalations and expiry warnings, even when nobody has the app open. */
class RunRules extends Command
{
    protected $signature = 'teamsayo:run-rules';
    protected $description = 'Send reminders and escalations defined by each workspace\'s automation rules.';

    public function handle(): int
    {
        $today = Carbon::today();
        $sent = 0;
        foreach (Workspace::whereIn('status', ['trialing', 'active', 'past_due'])->cursor() as $ws) {
            if ($ws->isReadOnly()) {
                continue;
            }
            $rules = AppItem::where('workspace_id', $ws->id)->where('collection', 'rules')->get()->map(fn ($r) => $r->data)->filter(fn ($r) => ! empty($r['on']));
            if ($rules->isEmpty()) {
                continue;
            }
            $people = AppItem::where('workspace_id', $ws->id)->where('collection', 'people')->get()->keyBy('item_key');
            $managerOf = function (?string $p) use ($people) {
                $m = $people[$p]->data['manager'] ?? '';
                if ($m) {
                    return $m;
                }
                $org = $people[$p]->data['org'] ?? null;
                $dir = $people->first(fn ($x) => ($x->data['org'] ?? null) === $org && ($x->data['role'] ?? '') === 'director');

                return $dir?->item_key ?? $people->first(fn ($x) => ($x->data['role'] ?? '') === 'admin')?->item_key;
            };
            $name = fn ($p) => $people[$p]->data['name'] ?? 'Someone';
            $actions = AppItem::where('workspace_id', $ws->id)->where('collection', 'actions')->get()->map(fn ($a) => $a->data);
            $records = AppItem::where('workspace_id', $ws->id)->where('collection', 'records')->whereIn('data->type', ['certifications', 'capacity'])->get()->map(fn ($r) => $r->data);

            foreach ($rules as $r) {
                $days = (int) ($r['days'] ?? 0);
                foreach ($actions as $a) {
                    if (($a['status'] ?? '') === 'done' || empty($a['due']) || empty($a['owner'])) {
                        continue;
                    }
                    $due = Carbon::parse($a['due']);
                    $diff = $today->diffInDays($due, false);
                    if ($r['trigger'] === 'due_soon' && $diff >= 0 && $diff <= $days) {
                        $sent += (int) (bool) ApiController::deliver($ws, $a['owner'], "⏰ Reminder: “{$a['title']}” is due {$due->format('j M Y')}.", ['kind' => 'action', 'id' => $a['id']], "{$r['id']}:{$a['id']}:{$a['due']}");
                    }
                    if ($r['trigger'] === 'overdue' && -$diff > $days) {
                        $m = $managerOf($a['owner']);
                        if ($m) {
                            $sent += (int) (bool) ApiController::deliver($ws, $m, "⚠ Escalation: “{$a['title']}” ({$name($a['owner'])}) is " . (-$diff) . ' days overdue.', ['kind' => 'action', 'id' => $a['id']], "{$r['id']}:{$a['id']}:{$a['due']}");
                        }
                        $sent += (int) (bool) ApiController::deliver($ws, $a['owner'], "⚠ “{$a['title']}” is overdue and has been escalated.", ['kind' => 'action', 'id' => $a['id']], "{$r['id']}o:{$a['id']}:{$a['due']}");
                    }
                }
                foreach ($records as $rec) {
                    $f = $rec['fields'] ?? [];
                    if ($r['trigger'] === 'cert_expiring' && ($rec['type'] ?? '') === 'certifications' && ! empty($f['renewal'] ?? $f['expires'] ?? null) && ! empty($f['person'])) {
                        $exp = Carbon::parse($f['renewal'] ?? $f['expires']);
                        $d = $today->diffInDays($exp, false);
                        if ($d >= 0 && $d <= $days) {
                            $sent += (int) (bool) ApiController::deliver($ws, $f['person'], "🎓 Your “{$f['name']}” expires {$exp->format('j M Y')}.", ['kind' => 'record', 'id' => $rec['id'], 'type' => 'certifications'], "{$r['id']}:{$rec['id']}");
                        }
                    }
                    if ($r['trigger'] === 'over_capacity' && ($rec['type'] ?? '') === 'capacity' && (float) ($f['allocated'] ?? 0) > (float) ($f['available'] ?? 0) && ! empty($f['person'])) {
                        if ($m = $managerOf($f['person'])) {
                            $sent += (int) (bool) ApiController::deliver($ws, $m, "📈 {$name($f['person'])} is allocated {$f['allocated']} h of {$f['available']} h this week.", ['kind' => 'record', 'id' => $rec['id'], 'type' => 'capacity'], "{$r['id']}:{$rec['id']}:{$f['allocated']}");
                        }
                    }
                }
            }
        }
        $this->info("Notifications sent: {$sent}");

        return self::SUCCESS;
    }
}
