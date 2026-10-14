<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

// Insert-only audit trail (audit_logs). Rows are never edited or deleted: the model refuses here,
// and database triggers refuse for raw queries. Read through AuditLogController (manager + owner).
class AuditLog extends Model
{
    const UPDATED_AT = null; // schema has created_at only

    protected $table = 'audit_logs';
    protected $primaryKey = 'audit_id';

    protected $fillable = ['user_id', 'action', 'entity', 'entity_id', 'ip'];

    public const ACTIONS = [
        'login', 'logout', 'login_failed',
        'order_status_changed', 'order_total_set',
        'invoice_issued', 'payment_receipt_created',
        'inventory_adjusted',
        'user_created', 'user_role_changed', 'user_deactivated', 'user_activated',
        'settings_updated',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('audit_logs is insert-only.'));
        static::deleting(fn () => throw new LogicException('audit_logs is insert-only.'));
    }

    /**
     * Records one event. Actor defaults to the signed-in user, IP to the current request.
     * Written after the surrounding transaction commits (immediately if there is none), so a
     * rolled-back write leaves no audit row. An audit failure is logged and never breaks the action.
     */
    public static function record(string $action, ?string $entity = null, ?int $entityId = null, ?int $userId = null): void
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new LogicException("Unknown audit action: {$action}");
        }

        $row = [
            'user_id'   => $userId ?? Auth::id(),
            'action'    => $action,
            'entity'    => $entity,
            'entity_id' => $entityId,
            'ip'        => request()->ip(),
        ];

        DB::afterCommit(function () use ($row) {
            try {
                self::create($row);
            } catch (\Throwable $e) {
                Log::warning("Audit log write failed ({$row['action']}): " . $e->getMessage());
            }
        });
    }
}
