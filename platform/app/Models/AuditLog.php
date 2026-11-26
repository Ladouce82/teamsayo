<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['workspace_id', 'user_id', 'action', 'detail', 'ip', 'user_agent'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $action, ?string $detail = null, ?int $workspaceId = null, ?int $userId = null): void
    {
        $request = request();
        static::create([
            'workspace_id' => $workspaceId,
            'user_id' => $userId ?? auth()->id(),
            'action' => mb_substr($action, 0, 120),
            'detail' => $detail !== null ? mb_substr($detail, 0, 2000) : null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }
}
