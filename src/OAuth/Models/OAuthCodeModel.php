<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $client_id
 * @property string $user_id
 * @property array<int, string> $scopes
 * @property string $code_challenge
 * @property string $code_challenge_method
 * @property string $redirect_uri
 * @property Carbon $expires_at
 * @property bool $used
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OAuthCodeModel extends Model
{
    /** @var string */
    protected $table = 'mcp_oauth_codes';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'client_id',
        'user_id',
        'scopes',
        'code_challenge',
        'code_challenge_method',
        'redirect_uri',
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
