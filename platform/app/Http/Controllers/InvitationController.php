<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Membership;
use App\Support\Provisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class InvitationController extends Controller
{
    private function find(string $token): ?Invitation
    {
        return Invitation::with('workspace', 'inviter')->where('token_hash', hash('sha256', $token))->first();
    }

    public function show(Request $request, string $token): View|RedirectResponse
    {
        $inv = $this->find($token);
        if (! $inv || ! $inv->isPending() || ! $inv->workspace) {
            return view('invitation', ['invalid' => true]);
        }
        if (! $request->user()) {
            $request->session()->put('invitation_token', $token);
            $request->session()->put('url.intended', route('invitations.show', $token));
        }

        return view('invitation', ['invitation' => $inv, 'token' => $token, 'invalid' => false]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $inv = $this->find($token);
        abort_unless($inv && $inv->isPending() && $inv->workspace, 404);
        $user = $request->user();
        if (strtolower($user->email) !== strtolower($inv->email)) {
            return back()->withErrors(['email' => "This invitation was sent to {$inv->email}. Sign in with that address to accept it."]);
        }
        $workspace = $inv->workspace;
        DB::transaction(function () use ($inv, $user, $workspace) {
            if (! Membership::where('workspace_id', $workspace->id)->where('user_id', $user->id)->exists()) {
                $m = Provisioner::addMember($workspace, $user, $inv->role, $inv->company_id, $inv->person_key, $inv->title);
                if ($inv->team_id) {
                    $m->teams()->syncWithoutDetaching([$inv->team_id]);
                }
            }
            $inv->update(['accepted_at' => now()]);
        });
        $request->session()->forget('invitation_token');
        AuditLog::record('Joined workspace', $user->email, $workspace->id);

        return redirect()->route('app', $workspace)->with('status', "Welcome to {$workspace->name}.");
    }
}
