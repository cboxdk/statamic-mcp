<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $token_hash
 * @property string $client_id
 * @property string $user_id
 * @property array<int, string> $scopes
 * @property Carbon $expires_at
 * @property bool $used
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OAuthRefreshTokenModel extends Model
{
    /** @var string */
    protected $table = 'mcp_oauth_refresh_tokens';

    /** @var list<string> */
    protected $fillable = [
        'token_hash',
        'client_id',
        'user_id',
        'scopes',
        'expires_at',
        'used',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'used' => 'boolean',
        ];
    }
}
