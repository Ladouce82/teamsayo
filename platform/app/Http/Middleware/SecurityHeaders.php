<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Security headers and a per-request script nonce for server-rendered pages. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(32);
        $request->attributes->set('csp_nonce', $nonce);
        View::share('cspNonce', $nonce);

        $response = $next($request);

        $isApp = (bool) $request->attributes->get('teamsayo_app_page');
        $paypal = 'https://www.paypal.com https://*.paypal.com https://*.paypalobjects.com';
        $csp = $isApp
            // The TeamSayo app uses inline event handlers, so its page allows inline scripts but nothing external.
            ? "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://img.youtube.com; media-src 'self' data: blob:; connect-src 'self' https://api.anthropic.com; frame-src https://www.youtube-nocookie.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
            : "default-src 'self'; script-src 'self' 'nonce-{$nonce}' {$paypal}; style-src 'self' 'unsafe-inline'; img-src 'self' data: {$paypal}; connect-src 'self' {$paypal}; frame-src {$paypal}; object-src 'none'; base-uri 'self'; form-action 'self' {$paypal}; frame-ancestors 'none'";

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(self "https://www.paypal.com")');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if (app()->environment('staging')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
