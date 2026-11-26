<?php

namespace App\Http\Controllers;

use App\Models\AppItem;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Team;
use App\Models\Workspace;
use App\Notifications\InvitationMail;
use App\Support\Provisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MemberController extends Controller
{
    private function guard(Request $request): Membership
    {
        $m = $request->attributes->get('membership');
        abort_unless($m->canManageMembers(), 403);

        return $m;
    }

    /** Directors can only manage people in their own company and cannot grant admin rights. */
    private function allowedRoles(Membership $me): array
    {
        return $me->isAdmin() ? Membership::ASSIGNABLE : ['director', 'manager', 'employee'];
    }

    public function index(Request $request, Workspace $workspace): View
    {
        $me = $this->guard($request);
        $members = Membership::with(['user:id,name,email,last_login_at,two_factor_confirmed_at', 'company', 'teams'])->where('workspace_id', $workspace->id)
            ->when(! $me->seesAllCompanies(), fn ($q) => $q->where('company_id', $me->company_id))->orderBy('role')->get();
        $invites = Invitation::where('workspace_id', $workspace->id)->whereNull('accepted_at')->whereNull('revoked_at')
            ->when(! $me->seesAllCompanies(), fn ($q) => $q->where('company_id', $me->company_id))->latest()->get();
        $people = AppItem::where('workspace_id', $workspace->id)->where('collection', 'people')->get()
            ->filter(fn ($p) => empty($p->data['mid']) && empty($p->data['invited']) && ($me->seesAllCompanies() || $p->company_id === $me->company_id));

        return view('members', [
            'workspace' => $workspace, 'me' => $me, 'members' => $members, 'invites' => $invites, 'people' => $people,
            'companies' => Company::where('workspace_id', $workspace->id)->orderBy('name')->get(),
            'teams' => Team::with('members.user')->where('workspace_id', $workspace->id)->get(),
            'roles' => $this->allowedRoles($me), 'seatsUsed' => $workspace->seatsUsed(), 'seatLimit' => $workspace->seatLimit(),
        ]);
    }

    public function invite(Request $request, Workspace $workspace): RedirectResponse
    {
        $me = $this->guard($request);
        abort_if($workspace->isReadOnly(), 402);
        $key = 'invite:' . $workspace->id;
        abort_if(RateLimiter::tooManyAttempts($key, 50), 429, 'Too many invitations in a short time.');
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'name' => ['nullable', 'string', 'max:120'],
            'role' => ['required', Rule::in($this->allowedRoles($me))],
            'company_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:120'],
            'person_key' => ['nullable', 'string', 'max:64'],
        ]);
        $email = Str::lower($data['email']);
        $company = isset($data['company_id']) ? Company::where('workspace_id', $workspace->id)->find($data['company_id']) : null;
        if (! $me->seesAllCompanies()) {
            $company = Company::find($me->company_id);
        }
        if ($workspace->seatsUsed() >= $workspace->seatLimit()) {
            return back()->withErrors(['email' => 'All seats are in use. Add seats on the Billing page first.']);
        }
        if (Membership::where('workspace_id', $workspace->id)->whereHas('user', fn ($q) => $q->where('email', $email))->exists()) {
            return back()->withErrors(['email' => 'This person is already a member.']);
        }
        $personKey = $data['person_key'] ?? null;
        if ($personKey && (! AppItem::where('workspace_id', $workspace->id)->where('collection', 'people')->where('item_key', $personKey)->exists()
            || Membership::where('workspace_id', $workspace->id)->where('person_key', $personKey)->exists())) {
            $personKey = null; // unknown, or already linked to someone's login
        }
        $personKey ??= Provisioner::newPersonKey();
        Invitation::where('workspace_id', $workspace->id)->where('email', $email)->whereNull('accepted_at')->update(['revoked_at' => now()]);
        $token = Str::random(48);
        $invitation = Invitation::create([
            'workspace_id' => $workspace->id, 'email' => $email, 'role' => $data['role'], 'company_id' => $company?->id, 'person_key' => $personKey,
            'title' => $data['title'] ?? null, 'token_hash' => hash('sha256', $token), 'invited_by' => $request->user()->id, 'expires_at' => now()->addDays(14),
        ]);
        Provisioner::ensureInvitedPerson($workspace, $personKey, $email, $data['name'] ?? Str::before($email, '@'), $data['role'], $company, $data['title'] ?? null);
        Notification::route('mail', $email)->notify(new InvitationMail($workspace, $request->user(), $token));
        RateLimiter::hit($key, 3600);
        AuditLog::record('Invited member', "{$email} as {$data['role']}", $workspace->id);

        return back()->with('status', "Invitation sent to {$email}.");
    }

    public function resend(Request $request, Workspace $workspace, Invitation $invitation): RedirectResponse
    {
        $this->guard($request);
        abort_unless($invitation->workspace_id === $workspace->id && $invitation->accepted_at === null, 404);
        $token = Str::random(48);
        $invitation->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(14), 'revoked_at' => null]);
        Notification::route('mail', $invitation->email)->notify(new InvitationMail($workspace, $request->user(), $token));

        return back()->with('status', 'Invitation sent again.');
    }

    public function revoke(Request $request, Workspace $workspace, Invitation $invitation): RedirectResponse
    {
        $this->guard($request);
        abort_unless($invitation->workspace_id === $workspace->id, 404);
        $invitation->update(['revoked_at' => now()]);
        AuditLog::record('Revoked invitation', $invitation->email, $workspace->id);

        return back()->with('status', 'Invitation revoked.');
    }

    public function update(Request $request, Workspace $workspace, Membership $member): RedirectResponse
    {
        $me = $this->guard($request);
        abort_unless($member->workspace_id === $workspace->id, 404);
        abort_if($member->role === 'owner' || (! $me->isAdmin() && ($member->isAdmin() || $member->company_id !== $me->company_id)), 403);
        $data = $request->validate([
            'role' => ['required', Rule::in($this->allowedRoles($me))],
            'company_id' => ['nullable', 'integer'],
            'manager_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:120'],
        ]);
        if ($member->id === $me->id && $data['role'] !== $me->role) {
            return back()->withErrors(['role' => 'You cannot change your own role.']);
        }
        $companyId = $me->seesAllCompanies() ? (Company::where('workspace_id', $workspace->id)->find($data['company_id'] ?? 0)?->id) : $me->company_id;
        $managerId = Membership::where('workspace_id', $workspace->id)->whereKey($data['manager_id'] ?? 0)->where('id', '!=', $member->id)->value('id');
        $member->update(['role' => $data['role'], 'company_id' => $companyId, 'manager_id' => $managerId, 'title' => $data['title'] ?? $member->title]);
        $managerKey = $managerId ? Membership::find($managerId)->person_key : '';
        Provisioner::ensurePersonItem($member->fresh(), null, ['manager' => $managerKey, 'title' => $member->title ?? '']);
        AuditLog::record('Changed member', "{$member->user->email}: {$data['role']}", $workspace->id);

        return back()->with('status', 'Member updated.');
    }

    public function remove(Request $request, Workspace $workspace, Membership $member): RedirectResponse
    {
        $me = $this->guard($request);
        abort_unless($member->workspace_id === $workspace->id, 404);
        abort_if($member->role === 'owner' || $member->id === $me->id || (! $me->isAdmin() && ($member->isAdmin() || $member->company_id !== $me->company_id)), 403);
        $email = $member->user->email;
        // The person record stays so their history (actions, records) remains; it is just no longer linked to a login.
        $item = AppItem::where('workspace_id', $workspace->id)->where('collection', 'people')->where('item_key', $member->person_key)->first();
        if ($item) {
            $d = $item->data;
            unset($d['mid']);
            $d['role'] = 'employee';
            $d['left'] = now()->toDateString();
            $item->update(['data' => $d, 'version' => $item->version + 1]);
        }
        $member->delete();
        AuditLog::record('Removed member', $email, $workspace->id);

        return back()->with('status', "{$email} no longer has access.");
    }

    public function saveTeam(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate([
            'team_id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:120'], 'company_id' => ['nullable', 'integer'],
            'members' => ['array'], 'members.*' => ['integer'], 'lead' => ['nullable', 'integer'],
        ]);
        $team = isset($data['team_id']) ? Team::where('workspace_id', $workspace->id)->findOrFail($data['team_id']) : new Team(['workspace_id' => $workspace->id]);
        $team->fill(['name' => $data['name'], 'company_id' => Company::where('workspace_id', $workspace->id)->find($data['company_id'] ?? 0)?->id])->save();
        $ids = Membership::where('workspace_id', $workspace->id)->whereIn('id', $data['members'] ?? [])->pluck('id');
        $team->members()->sync($ids->mapWithKeys(fn ($id) => [$id => ['is_lead' => (int) ($data['lead'] ?? 0) === $id]])->all());
        foreach (Membership::whereIn('id', $ids)->get() as $m) {
            Provisioner::ensurePersonItem($m, null, ['team' => $team->name]);
        }
        AuditLog::record('Saved team', $team->name, $workspace->id);

        return back()->with('status', 'Team saved.');
    }

    public function deleteTeam(Request $request, Workspace $workspace, Team $team): RedirectResponse
    {
        $this->guard($request);
        abort_unless($team->workspace_id === $workspace->id, 404);
        $team->delete();

        return back()->with('status', 'Team deleted.');
    }
}
