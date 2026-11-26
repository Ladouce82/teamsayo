<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Membership;
use App\Support\Totp;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $qr = null;
        if ($user->two_factor_secret && ! $user->two_factor_confirmed_at) {
            $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd()));
            $qr = $writer->writeString(Totp::uri($user->two_factor_secret, $user->email));
        }
        $sessions = DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        return view('account', ['user' => $user, 'qr' => $qr, 'sessions' => $sessions, 'currentSession' => $request->session()->getId(), 'recovery' => session('recovery_codes')]);
    }

    public function profile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email,' . $user->id]]);
        $emailChanged = Str::lower($data['email']) !== $user->email;
        $user->fill(['name' => $data['name'], 'email' => Str::lower($data['email'])]);
        if ($emailChanged) {
            $user->email_verified_at = null;
        }
        $user->save();
        foreach ($user->memberships as $m) {
            \App\Support\Provisioner::ensurePersonItem($m, $user, ['name' => $user->name]);
        }
        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }
        AuditLog::record('Updated profile', $emailChanged ? 'email changed' : null);

        return back()->with('status', $emailChanged ? 'Saved. Please confirm your new email address.' : 'Saved.');
    }

    public function password(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()]]);
        $request->user()->forceFill(['password' => $request->input('password')])->save();
        Auth::logoutOtherDevices($request->input('password'));
        AuditLog::record('Changed password');

        return back()->with('status', 'Password changed. Other devices were signed out.');
    }

    public function enableTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $request->user()->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => null])->save();

        return back()->with('status', 'Scan the QR code with your authenticator app, then enter the 6-digit code.');
    }

    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate(['code' => ['required', 'string']]);
        if (! $user->two_factor_secret || ! Totp::verify($user->two_factor_secret, $request->input('code'))) {
            return back()->withErrors(['code' => 'That code is not valid. Check the time on your phone and try again.']);
        }
        $codes = collect(range(1, 8))->map(fn () => Str::lower(Str::random(5) . '-' . Str::random(5)))->all();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();
        AuditLog::record('Enabled two-factor authentication');

        return back()->with('status', 'Two-factor authentication is on.')->with('recovery_codes', $codes);
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $request->user()->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
        AuditLog::record('Disabled two-factor authentication');

        return back()->with('status', 'Two-factor authentication is off.');
    }

    public function logoutSession(Request $request, string $id): RedirectResponse
    {
        DB::table('sessions')->where('user_id', $request->user()->id)->where('id', $id)->where('id', '!=', $request->session()->getId())->delete();
        AuditLog::record('Signed out another device');

        return back()->with('status', 'That device has been signed out.');
    }

    /** GDPR: everything we hold about the account itself. Workspace data is exported by workspace admins. */
    public function export(Request $request): JsonResponse
    {
        $user = $request->user();
        AuditLog::record('Downloaded own account data');

        return response()->json([
            'account' => $user->only(['name', 'email', 'created_at', 'email_verified_at', 'last_login_at', 'locale']) + ['two_factor' => $user->hasTwoFactor()],
            'memberships' => Membership::with('workspace:id,name')->where('user_id', $user->id)->get(['workspace_id', 'role', 'title', 'created_at']),
            'audit' => AuditLog::where('user_id', $user->id)->latest('id')->limit(1000)->get(['action', 'detail', 'ip', 'created_at']),
        ])->header('Content-Disposition', 'attachment; filename="teamsayo-account-' . now()->format('Y-m-d') . '.json"');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate(['password' => ['required', 'current_password']]);
        $owned = Membership::where('user_id', $user->id)->where('role', 'owner')->with('workspace')->get()->filter(fn ($m) => $m->workspace && $m->workspace->memberships()->count() > 1);
        if ($owned->isNotEmpty()) {
            return back()->withErrors(['password' => 'You own a workspace with other members (' . $owned->pluck('workspace.name')->implode(', ') . '). Transfer ownership or delete it first.']);
        }
        AuditLog::record('Deleted account', $user->email, null, $user->id);
        foreach (Membership::where('user_id', $user->id)->where('role', 'owner')->with('workspace')->get() as $m) {
            $m->workspace?->delete();
        }
        Auth::logout();
        $user->delete();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Your account has been deleted.');
    }
}
