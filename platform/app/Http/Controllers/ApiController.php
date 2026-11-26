<?php

namespace App\Http\Controllers;

use App\Models\AppItem;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\StoredFile;
use App\Models\Workspace;
use App\Notifications\AppAlert;
use App\Support\ItemContext;
use App\Support\ItemPolicy;
use App\Support\ItemStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApiController extends Controller
{
    private function store(Request $request): array
    {
        $workspace = $request->attributes->get('workspace');
        $membership = $request->attributes->get('membership');
        $ctx = new ItemContext($workspace, $membership);

        return [$workspace, $membership, $ctx, new ItemStore($ctx, $request->user())];
    }

    /** All records this member may see. */
    public function items(Request $request): JsonResponse
    {
        [, , , $store] = $this->store($request);
        $since = $request->query('since') ? Carbon::parse($request->query('since')) : null;
        $now = now()->subSecond()->toIso8601String();

        return response()->json([
            'items' => $store->readable($since),
            'deleted' => $since ? $store->deletedSince($since) : [],
            'serverTime' => $now,
        ]);
    }

    /** Saves a batch of changes from the app. */
    public function sync(Request $request): JsonResponse
    {
        [$workspace, $membership, , $store] = $this->store($request);
        if ($workspace->isReadOnly()) {
            return response()->json(['error' => 'read_only', 'message' => 'Your trial or subscription has ended. Choose a plan to keep editing.'], 402);
        }
        $data = $request->validate(['upserts' => ['array', 'max:500'], 'deletes' => ['array', 'max:500'], 'events' => ['array', 'max:30']]);
        $result = $store->sync($data['upserts'] ?? [], $data['deletes'] ?? []);
        foreach (array_slice($data['events'] ?? [], 0, 30) as $e) {
            if (is_array($e) && isset($e['a'])) {
                AuditLog::record(mb_substr((string) $e['a'], 0, 120), isset($e['d']) ? mb_substr((string) $e['d'], 0, 500) : null, $workspace->id);
            }
        }

        return response()->json($result);
    }

    public function notifications(Request $request): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $membership = $request->attributes->get('membership');
        $list = AppNotification::where('workspace_id', $workspace->id)->where('person_key', $membership->person_key)
            ->latest('id')->limit(100)->get()
            ->map(fn ($n) => ['id' => $n->id, 'ts' => $n->created_at->toIso8601String(), 'text' => $n->text, 'ref' => $n->ref, 'read' => (bool) $n->read_at, 'channels' => $n->emailed_at ? ['In-app', 'Email'] : ['In-app']]);

        return response()->json(['notifications' => $list]);
    }

    public function readNotifications(Request $request): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $membership = $request->attributes->get('membership');
        AppNotification::where('workspace_id', $workspace->id)->where('person_key', $membership->person_key)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Lets the app notify a colleague (e.g. "new action assigned to you"). */
    public function notify(Request $request): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $data = $request->validate(['to' => ['required', 'string', 'max:64'], 'text' => ['required', 'string', 'max:500'], 'ref' => ['nullable', 'array'], 'key' => ['nullable', 'string', 'max:150']]);
        $exists = AppItem::where('workspace_id', $workspace->id)->where('collection', 'people')->where('item_key', $data['to'])->exists();
        if (! $exists) {
            return response()->json(['ok' => false], 422);
        }
        self::deliver($workspace, $data['to'], $data['text'], $data['ref'] ?? null, $data['key'] ?? null);

        return response()->json(['ok' => true]);
    }

    /** Stores a notification and emails the person if they have a login and email is switched on. */
    public static function deliver(Workspace $workspace, string $personKey, string $text, ?array $ref = null, ?string $dedupe = null): ?AppNotification
    {
        if ($dedupe && AppNotification::where('workspace_id', $workspace->id)->where('dedupe_key', $dedupe)->exists()) {
            return null;
        }
        $n = AppNotification::create(['workspace_id' => $workspace->id, 'person_key' => $personKey, 'dedupe_key' => $dedupe, 'text' => $text, 'ref' => $ref]);
        $channels = AppItem::where('workspace_id', $workspace->id)->where('collection', 'config')->where('item_key', 'wsettings')->value('data');
        $emailOn = ! is_array($channels) || ($channels['channels']['email'] ?? true);
        $member = Membership::with('user')->where('workspace_id', $workspace->id)->where('person_key', $personKey)->first();
        if ($emailOn && $member?->user) {
            $member->user->notify(new AppAlert($workspace, $text));
            $n->update(['emailed_at' => now()]);
        }

        return $n;
    }

    public function audit(Request $request): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $membership = $request->attributes->get('membership');
        abort_unless(in_array($membership->role, ['owner', 'admin', 'director', 'auditor'], true), 403);
        $people = Membership::where('workspace_id', $workspace->id)->pluck('person_key', 'user_id');
        $rows = AuditLog::with('user:id,name')->where('workspace_id', $workspace->id)->latest('id')->limit(300)->get()
            ->map(fn ($a) => ['id' => $a->id, 'ts' => $a->created_at->toIso8601String(), 'user' => $people[$a->user_id] ?? null, 'name' => $a->user?->name ?? 'System', 'action' => $a->action, 'detail' => $a->detail]);

        return response()->json(['audit' => $rows]);
    }

    public function upload(Request $request): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $membership = $request->attributes->get('membership');
        abort_if($workspace->isReadOnly() || $membership->role === 'auditor', 403);
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt,json,html,htm,md,png,jpg,jpeg,gif,webp,mp4,webm,mov,mp3,m4a,wav,ogg,zip']]);
        $file = $request->file('file');
        $path = $file->store("workspaces/{$workspace->id}", 'local');
        $stored = StoredFile::create(['workspace_id' => $workspace->id, 'user_id' => $request->user()->id, 'name' => mb_substr($file->getClientOriginalName(), 0, 190), 'mime' => $file->getMimeType() ?: 'application/octet-stream', 'size' => $file->getSize(), 'path' => $path]);
        AuditLog::record('Uploaded file', $stored->name, $workspace->id);

        return response()->json(['url' => route('api.file', [$workspace, $stored->uuid]), 'name' => $stored->name, 'size' => $stored->size]);
    }

    public function file(Request $request, Workspace $workspace, string $uuid): StreamedResponse
    {
        $stored = StoredFile::where('workspace_id', $workspace->id)->where('uuid', $uuid)->firstOrFail();
        $inline = preg_match('#^(image|video|audio)/#', $stored->mime) === 1;

        return Storage::disk('local')->response($stored->path, $stored->name, [
            'Content-Type' => $stored->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ], $inline ? 'inline' : 'attachment');
    }
}
