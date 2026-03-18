<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Storage\Audit;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $level
 * @property string $message
 * @property string|null $tool
 * @property string|null $action
 * @property string|null $status
 * @property string|null $correlation_id
 * @property float|null $duration_ms
 * @property array<string, mixed>|null $context
 * @property Carbon $logged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class McpAuditEntry extends Model
{
    protected $table = 'mcp_audit_logs';

    /** @var list<string> */
    protected $fillable = [
        'level',
        'message',
        'tool',
        'action',
        'status',
        'correlation_id',
        'duration_ms',
        'context',
        'logged_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'duration_ms' => 'float',
            'logged_at' => 'datetime',
        ];
    }
}
