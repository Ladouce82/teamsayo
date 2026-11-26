<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Keeps the staging site private behind a shared password (HTTP basic auth). */
class StagingGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $password = config('teamsayo.staging_password');
        if (! app()->environment('staging') || ! $password || $request->is('webhooks/*') || $request->is('up')) {
            return $next($request);
        }
        if ($request->getUser() === 'teamsayo' && hash_equals($password, (string) $request->getPassword())) {
            return $next($request);
        }

        return response('Staging access only.', 401, ['WWW-Authenticate' => 'Basic realm="TeamSayo staging"']);
    }
}
