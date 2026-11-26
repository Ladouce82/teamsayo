<?php

namespace App\Support;

use App\Models\AppItem;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Reads and writes app records with permission checks, version control and audit logging. */
class ItemStore
{
    public const MAX_ITEM_BYTES = 2_000_000;

    public function __construct(private ItemContext $ctx, private User $user)
    {
    }

    /** @return array<int,array{c:string,k:string,v:int,d:array}> */
    public function readable(?Carbon $since = null): array
    {
        $q = AppItem::where('workspace_id', $this->ctx->workspace->id);
        if ($since) {
            $q->where('updated_at', '>', $since);
        }
        $out = [];
        $people = $this->peopleOverrides();
        foreach ($q->orderBy('id')->cursor() as $item) {
            $d = $item->data ?? [];
            if (! ItemPolicy::canRead($this->ctx, $item->collection, $d, $item->company_id, $item->subject_key)) {
                continue;
            }
            if ($item->collection === 'people' && isset($people[$item->item_key])) {
                $d = array_merge($d, $people[$item->item_key]);
            }
            $out[] = ['c' => $item->collection, 'k' => $item->item_key, 'v' => $item->version, 'd' => $d];
        }

        return $out;
    }

    /** Keys deleted since a point in time (so other browsers can drop them). */
    public function deletedSince(Carbon $since): array
    {
        return AppItem::onlyTrashed()->where('workspace_id', $this->ctx->workspace->id)->where('deleted_at', '>', $since)
            ->get(['collection', 'item_key'])->map(fn ($i) => ['c' => $i->collection, 'k' => $i->item_key])->all();
    }

    /** Role, email and login link always come from the account, never from the editable record. */
    private function peopleOverrides(): array
    {
        $out = [];
        $members = Membership::with('user:id,email,name')->where('workspace_id', $this->ctx->workspace->id)->get();
        foreach ($members as $m) {
            $out[$m->person_key] = ['role' => $m->appRole(), 'email' => $m->user?->email, 'mid' => $m->id, 'invited' => false];
        }

        return $out;
    }

    /**
     * @param  array<int,array{c:string,k:string,d:array,v?:int}>  $upserts
     * @param  array<int,array{c:string,k:string,v?:int}>  $deletes
     * @return array{ok:array,rejected:array}
     */
    public function sync(array $upserts, array $deletes): array
    {
        $ok = [];
        $rejected = [];
        $changes = 0;
        $overrides = $this->peopleOverrides();

        DB::transaction(function () use ($upserts, $deletes, &$ok, &$rejected, &$changes, $overrides) {
            foreach (array_slice($upserts, 0, 500) as $op) {
                $c = (string) ($op['c'] ?? '');
                $k = (string) ($op['k'] ?? '');
                $d = $op['d'] ?? null;
                if (! $this->validKey($c, $k) || ! is_array($d)) {
                    $rejected[] = ['c' => $c, 'k' => $k, 'reason' => 'invalid'];
                    continue;
                }
                if (strlen(json_encode($d)) > self::MAX_ITEM_BYTES) {
                    $rejected[] = ['c' => $c, 'k' => $k, 'reason' => 'too_large'];
                    continue;
                }
                $item = AppItem::withTrashed()->where('workspace_id', $this->ctx->workspace->id)->where('collection', $c)->where('item_key', $k)->lockForUpdate()->first();
                $live = $item && ! $item->trashed();
                $old = $live ? ($item->data ?? []) : null;
                $oldSubject = $live ? $item->subject_key : null;
                $oldCompany = $live ? $item->company_id : null;

                if ($live && isset($op['v']) && (int) $op['v'] !== $item->version) {
                    $rejected[] = $this->conflict($item);
                    continue;
                }
                $d = ItemPolicy::restrictFields($this->ctx, $c, $old, $d);
                if ($c === 'people' && isset($overrides[$k])) {
                    $d = array_merge($d, $overrides[$k]);
                }
                $subject = ItemPolicy::subjectOf($c, $k, $d);
                $company = ItemPolicy::companyOf($this->ctx, $c, $k, $d, $subject);
                if (! ItemPolicy::canWrite($this->ctx, $c, $old, $d, $oldCompany, $company, $oldSubject, $subject)) {
                    $rejected[] = ['c' => $c, 'k' => $k, 'reason' => 'forbidden'] + ($live && ItemPolicy::canRead($this->ctx, $c, $old, $oldCompany, $oldSubject) ? ['v' => $item->version, 'd' => $old] : []);
                    continue;
                }
                if ($c === 'orgs') {
                    $company = $this->syncCompany($k, $d);
                }
                if ($live && $old == $d) {
                    $ok[] = ['c' => $c, 'k' => $k, 'v' => $item->version];
                    continue;
                }
                $item ??= new AppItem(['workspace_id' => $this->ctx->workspace->id, 'collection' => $c, 'item_key' => $k, 'created_by' => $this->user->id, 'version' => 0]);
                $item->fill(['company_id' => $company, 'subject_key' => $subject, 'data' => $d, 'version' => $item->version + 1, 'updated_by' => $this->user->id]);
                $item->deleted_at = null;
                $item->save();
                $ok[] = ['c' => $c, 'k' => $k, 'v' => $item->version];
                $changes++;
                if ($c === 'people') {
                    $this->ctx->managerOf[$k] = (string) ($d['manager'] ?? '');
                    $this->ctx->personCompany[$k] = $company;
                }
            }

            foreach (array_slice($deletes, 0, 500) as $op) {
                $c = (string) ($op['c'] ?? '');
                $k = (string) ($op['k'] ?? '');
                if (! $this->validKey($c, $k)) {
                    continue;
                }
                $item = AppItem::where('workspace_id', $this->ctx->workspace->id)->where('collection', $c)->where('item_key', $k)->lockForUpdate()->first();
                if (! $item) {
                    $ok[] = ['c' => $c, 'k' => $k, 'deleted' => true];
                    continue;
                }
                $isLogin = $c === 'people' && isset($overrides[$k]);
                if ($isLogin || $c === 'orgs' && Membership::where('company_id', $item->company_id)->exists()
                    || ! ItemPolicy::canWrite($this->ctx, $c, $item->data ?? [], null, $item->company_id, null, $item->subject_key, null)) {
                    $rejected[] = ['c' => $c, 'k' => $k, 'reason' => $isLogin ? 'remove_member_first' : 'forbidden'];
                    continue;
                }
                $item->updated_by = $this->user->id;
                $item->save();
                $item->delete();
                if ($c === 'orgs' && $item->company_id) {
                    Company::whereKey($item->company_id)->delete();
                }
                $ok[] = ['c' => $c, 'k' => $k, 'deleted' => true];
                $changes++;
            }
        });

        if ($changes) {
            AuditLog::record('Saved changes', "{$changes} record(s)", $this->ctx->workspace->id, $this->user->id);
        }

        return ['ok' => $ok, 'rejected' => $rejected];
    }

    private function conflict(AppItem $item): array
    {
        $d = $item->data ?? [];
        $readable = ItemPolicy::canRead($this->ctx, $item->collection, $d, $item->company_id, $item->subject_key);

        return ['c' => $item->collection, 'k' => $item->item_key, 'reason' => 'conflict'] + ($readable ? ['v' => $item->version, 'd' => $d] : []);
    }

    private function validKey(string $c, string $k): bool
    {
        return in_array($c, ItemPolicy::COLLECTIONS, true) && $k !== '' && strlen($k) <= 191;
    }

    /** Company records are created and renamed through the app (admins only). */
    private function syncCompany(string $key, array $d): ?int
    {
        $company = Company::firstOrNew(['workspace_id' => $this->ctx->workspace->id, 'key' => $key]);
        $company->name = mb_substr((string) ($d['name'] ?? 'Company'), 0, 120);
        $company->color = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($d['color'] ?? '')) ? $d['color'] : '#0b8f8f';
        $company->save();
        $this->ctx->companyIds[$key] = $company->id;

        return $company->id;
    }
}
