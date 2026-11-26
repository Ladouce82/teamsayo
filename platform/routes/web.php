<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

// Public
Route::get('/legal/{page}', [LegalController::class, 'show'])->name('legal');
Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('/webhooks/paypal', [WebhookController::class, 'paypal'])->name('webhooks.paypal');

// Guests
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::get('/two-factor', [AuthController::class, 'showTwoFactor'])->name('two-factor.challenge');
    Route::post('/two-factor', [AuthController::class, 'twoFactor'])->middleware('throttle:20,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
    Route::get('/forgot-password', [AuthController::class, 'showForgot'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendReset'])->middleware('throttle:4,1')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:6,1')->name('password.update');
});

// Signed in
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/email/verify', [AuthController::class, 'verifyNotice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])->middleware('throttle:3,1')->name('verification.send');

    Route::get('/account', [AccountController::class, 'show'])->name('account');
    Route::post('/account/profile', [AccountController::class, 'profile'])->name('account.profile');
    Route::post('/account/password', [AccountController::class, 'password'])->middleware('throttle:6,1')->name('account.password');
    Route::post('/account/two-factor', [AccountController::class, 'enableTwoFactor'])->name('account.2fa.enable');
    Route::post('/account/two-factor/confirm', [AccountController::class, 'confirmTwoFactor'])->middleware('throttle:10,1')->name('account.2fa.confirm');
    Route::delete('/account/two-factor', [AccountController::class, 'disableTwoFactor'])->name('account.2fa.disable');
    Route::delete('/account/sessions/{id}', [AccountController::class, 'logoutSession'])->name('account.session.destroy');
    Route::get('/account/export', [AccountController::class, 'export'])->name('account.export');
    Route::delete('/account', [AccountController::class, 'destroy'])->name('account.destroy');

    Route::middleware('verified')->group(function () {
        Route::get('/', [WorkspaceController::class, 'home'])->name('home');
        Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding');
        Route::post('/onboarding', [OnboardingController::class, 'store'])->middleware('throttle:10,1');
        Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');

        Route::prefix('/w/{workspace}')->middleware('workspace')->group(function () {
            Route::get('/', [WorkspaceController::class, 'app'])->name('app');
            Route::get('/settings', [WorkspaceController::class, 'settings'])->name('workspace.settings');
            Route::post('/settings', [WorkspaceController::class, 'rename'])->name('workspace.rename');
            Route::get('/export', [WorkspaceController::class, 'export'])->name('workspace.export');
            Route::delete('/', [WorkspaceController::class, 'destroy'])->name('workspace.destroy');

            Route::get('/members', [MemberController::class, 'index'])->name('members');
            Route::post('/members/invite', [MemberController::class, 'invite'])->name('members.invite');
            Route::post('/members/invitations/{invitation}/resend', [MemberController::class, 'resend'])->name('members.resend');
            Route::delete('/members/invitations/{invitation}', [MemberController::class, 'revoke'])->name('members.revoke');
            Route::patch('/members/{member}', [MemberController::class, 'update'])->name('members.update');
            Route::delete('/members/{member}', [MemberController::class, 'remove'])->name('members.remove');
            Route::post('/teams', [MemberController::class, 'saveTeam'])->name('teams.save');
            Route::delete('/teams/{team}', [MemberController::class, 'deleteTeam'])->name('teams.delete');

            Route::get('/billing', [BillingController::class, 'show'])->name('billing');
            Route::post('/billing/confirm', [BillingController::class, 'confirm'])->name('billing.confirm');
            Route::post('/billing/seats', [BillingController::class, 'seats'])->name('billing.seats');
            Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
            Route::get('/billing/invoices/{payment}', [BillingController::class, 'invoice'])->name('billing.invoice');
        });

        Route::prefix('/api/w/{workspace}')->middleware(['workspace', 'throttle:240,1'])->group(function () {
            Route::get('/items', [ApiController::class, 'items'])->name('api.items');
            Route::post('/sync', [ApiController::class, 'sync'])->name('api.sync');
            Route::get('/notifications', [ApiController::class, 'notifications'])->name('api.notifications');
            Route::post('/notifications', [ApiController::class, 'notify'])->middleware('throttle:60,1')->name('api.notify');
            Route::post('/notifications/read', [ApiController::class, 'readNotifications'])->name('api.notifications.read');
            Route::get('/audit', [ApiController::class, 'audit'])->name('api.audit');
            Route::post('/files', [ApiController::class, 'upload'])->middleware('throttle:60,1')->name('api.upload');
            Route::get('/files/{uuid}', [ApiController::class, 'file'])->name('api.file');
        });

        Route::get('/admin', [AdminController::class, 'index'])->middleware('staff')->name('admin');
    });
});
