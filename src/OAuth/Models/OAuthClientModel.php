<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $client_id
 * @property string $client_name
 * @property array<int, string> $redirect_uris
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OAuthClientModel extends Model
{
    /** @var string */
    protected $table = 'mcp_oauth_clients';

    /** @var string */
    protected $primaryKey = 'client_id';

    /** @var bool */
    public $incrementing = false;

    /** @var string */
    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'client_id',
        'client_name',
        'redirect_uris',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
        ];
    }
}
