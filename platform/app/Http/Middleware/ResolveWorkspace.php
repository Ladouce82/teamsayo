<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Loads the workspace from the URL and makes sure the signed-in user belongs to it. */
class ResolveWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->route('workspace');
        if (! $workspace instanceof Workspace) {
            $workspace = Workspace::where('uuid', (string) $workspace)->first();
        }
        abort_unless($workspace, 404);
        $membership = $request->user()?->membershipFor($workspace);
        abort_unless($membership, 404); // do not reveal that the workspace exists

        $request->attributes->set('workspace', $workspace);
        $request->attributes->set('membership', $membership);
        if ($request->user()->current_workspace_id !== $workspace->id) {
            $request->user()->forceFill(['current_workspace_id' => $workspace->id])->save();
        }

        return $next($request);
    }
}
