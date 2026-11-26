<?php

namespace App\Http\Controllers;

use App\Models\AppItem;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkspaceController extends Controller
{
    /** Where a signed-in user lands: their workspace, a choice of workspaces, an invitation, or onboarding. */
    public function home(Request $request): RedirectResponse|View
    {
        $user = $request->user();
        if ($token = $request->session()->pull('invitation_token')) {
            return redirect()->route('invitations.show', $token);
        }
        $memberships = $user->memberships()->with('workspace')->get()->filter(fn ($m) => $m->workspace);
        if ($memberships->isEmpty()) {
            return redirect()->route('onboarding');
        }
        if ($memberships->count() === 1) {
            return redirect()->route('app', $memberships->first()->workspace);
        }
        if ($request->query('choose') === null && ($current = $memberships->firstWhere('workspace_id', $user->current_workspace_id))) {
            return redirect()->route('app', $current->workspace);
        }

        return view('workspaces', ['memberships' => $memberships]);
    }

    /** The TeamSayo app, connected to this workspace's data. */
    public function app(Request $request, Workspace $workspace): Response
    {
        $membership = $request->attributes->get('membership');
        $user = $request->user();
        $request->attributes->set('teamsayo_app_page', true);

        $html = @file_get_contents(resource_path('app/teamsayo.html'));
        abort_unless($html, 500, 'App bundle missing.');
        $server = file_get_contents(resource_path('app/server.js'));
        $boot = [
            'api' => url("/api/w/{$workspace->uuid}"),
            'csrf' => csrf_token(),
            'urls' => [
                'billing' => route('billing', $workspace), 'members' => route('members', $workspace), 'account' => route('account'),
                'settings' => route('workspace.settings', $workspace), 'workspaces' => route('home', ['choose' => 1]), 'logout' => route('logout'),
                'legal' => route('legal', 'privacy'), 'admin' => $user->is_staff ? route('admin') : null,
            ],
            'me' => ['userId' => $user->id, 'personKey' => $membership->person_key, 'role' => $membership->role, 'appRole' => $membership->appRole(), 'name' => $user->name, 'email' => $user->email, 'companyId' => $membership->company_id, 'twoFactor' => $user->hasTwoFactor()],
            'workspace' => [
                'uuid' => $workspace->uuid, 'name' => $workspace->name, 'plan' => $workspace->plan, 'status' => $workspace->status, 'cycle' => $workspace->cycle,
                'trialEndsAt' => $workspace->trial_ends_at?->toIso8601String(), 'seats' => $workspace->seats, 'seatsUsed' => $workspace->seatsUsed(),
                'readOnly' => $workspace->isReadOnly(), 'nonprofit' => $workspace->nonprofit, 'pack' => $workspace->settings['pack'] ?? null,
            ],
            'allowDemo' => (bool) config('teamsayo.allow_demo_data'),
            'env' => app()->environment(),
        ];
        $inject = '<script id="ts-server">window.TS_BOOT=' . json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) . ";\n" . $server . '</script>';
        $pos = strrpos($html, '</body>');
        $html = substr($html, 0, $pos) . $inject . "\n" . substr($html, $pos);

        return response($html)->header('Cache-Control', 'no-store');
    }

    public function settings(Request $request, Workspace $workspace): View
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->isAdmin(), 403);

        return view('workspace-settings', ['workspace' => $workspace, 'membership' => $membership]);
    }

    public function rename(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless($request->attributes->get('membership')->isAdmin(), 403);
        $workspace->update($request->validate(['name' => ['required', 'string', 'max:120']]));
        AuditLog::record('Renamed workspace', $workspace->name, $workspace->id);

        return back()->with('status', 'Workspace renamed.');
    }

    /** Full export of the workspace's data (GDPR data portability). */
    public function export(Request $request, Workspace $workspace): StreamedResponse
    {
        abort_unless($request->attributes->get('membership')->isAdmin(), 403);
        AuditLog::record('Exported workspace data', null, $workspace->id);

        return response()->streamDownload(function () use ($workspace) {
            echo '{"workspace":' . json_encode($workspace->only(['uuid', 'name', 'plan', 'status', 'created_at'])) . ',"members":'
                . json_encode(Membership::with('user:id,name,email')->where('workspace_id', $workspace->id)->get(['user_id', 'role', 'person_key', 'title', 'company_id'])) . ',"items":[';
            $first = true;
            foreach (AppItem::where('workspace_id', $workspace->id)->orderBy('id')->cursor() as $item) {
                echo ($first ? '' : ',') . json_encode(['c' => $item->collection, 'k' => $item->item_key, 'd' => $item->data, 'updated_at' => $item->updated_at]);
                $first = false;
            }
            echo ']}';
        }, 'teamsayo-' . str($workspace->name)->slug() . '-' . now()->format('Y-m-d') . '.json', ['Content-Type' => 'application/json']);
    }

    public function destroy(Request $request, Workspace $workspace): RedirectResponse
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->role === 'owner', 403);
        $request->validate(['confirm' => ['required', 'in:' . $workspace->name], 'password' => ['required', 'current_password']]);
        AuditLog::record('Deleted workspace', $workspace->name, $workspace->id);
        $workspace->delete(); // soft delete; purged after 30 days by teamsayo:purge

        return redirect()->route('home', ['choose' => 1])->with('status', 'Workspace deleted. It can be restored by support for 30 days.');
    }
}
