<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $key = 'login:' . Str::lower($data['email']) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again in ' . RateLimiter::availableIn($key) . ' seconds.']);
        }

        $user = User::where('email', Str::lower($data['email']))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            AuditLog::record('Failed sign-in', $data['email'], null, $user?->id);
            throw ValidationException::withMessages(['email' => 'These details do not match our records.']);
        }
        RateLimiter::clear($key);

        if ($user->hasTwoFactor()) {
            $request->session()->put('login.2fa', ['id' => $user->id, 'remember' => $request->boolean('remember'), 'at' => time()]);

            return redirect()->route('two-factor.challenge');
        }

        return $this->completeLogin($request, $user, $request->boolean('remember'));
    }

    private function completeLogin(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLog::record('Signed in', $user->hasTwoFactor() ? 'with two-factor authentication' : null, $user->current_workspace_id, $user->id);

        return redirect()->intended(route('home'));
    }

    public function showTwoFactor(Request $request): View|RedirectResponse
    {
        return $request->session()->has('login.2fa') ? view('auth.two-factor') : redirect()->route('login');
    }

    public function twoFactor(Request $request): RedirectResponse
    {
        $pending = $request->session()->get('login.2fa');
        if (! $pending || time() - $pending['at'] > 600) {
            $request->session()->forget('login.2fa');

            return redirect()->route('login')->withErrors(['email' => 'Please sign in again.']);
        }
        $key = 'twofactor:' . $pending['id'];
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Try again in a minute.']);
        }
        $user = User::findOrFail($pending['id']);
        $code = trim((string) $request->input('code'));
        $recovery = trim((string) $request->input('recovery_code'));
        $ok = false;
        if ($code !== '') {
            $ok = Totp::verify($user->two_factor_secret, $code);
        } elseif ($recovery !== '') {
            $codes = $user->two_factor_recovery_codes ?? [];
            foreach ($codes as $i => $c) {
                if (hash_equals($c, $recovery)) {
                    unset($codes[$i]);
                    $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                    $ok = true;
                    AuditLog::record('Used a recovery code', null, null, $user->id);
                    break;
                }
            }
        }
        if (! $ok) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['code' => 'That code is not valid.']);
        }
        RateLimiter::clear($key);
        $request->session()->forget('login.2fa');

        return $this->completeLogin($request, $user, (bool) $pending['remember']);
    }

    public function logout(Request $request): RedirectResponse
    {
        AuditLog::record('Signed out', null, $request->user()?->current_workspace_id);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
            'terms' => ['accepted'],
        ], ['terms.accepted' => 'Please accept the terms and privacy policy.']);

        $user = User::create(['name' => $data['name'], 'email' => Str::lower($data['email']), 'password' => $data['password']]);
        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();
        AuditLog::record('Account created', null, null, $user->id);

        return redirect()->route('verification.notice');
    }

    public function verifyNotice(Request $request): View|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail() ? redirect()->route('home') : view('auth.verify-email');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();
        AuditLog::record('Email verified');

        return redirect()->route('home')->with('status', 'Email address confirmed.');
    }

    public function resendVerification(Request $request): RedirectResponse
    {
        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'We sent a new confirmation link.');
    }

    public function showForgot(): View
    {
        return view('auth.forgot-password');
    }

    public function sendReset(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => Str::lower($request->input('email'))]);

        // Same answer whether or not the address exists, so accounts cannot be discovered.
        return back()->with('status', 'If an account exists for that address, a reset link is on its way.');
    }

    public function showReset(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ]);
        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
            AuditLog::record('Password reset', null, null, $user->id);
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', 'Your password has been reset. Please sign in.')
            : back()->withErrors(['email' => __($status)]);
    }
}
