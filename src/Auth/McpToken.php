<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Facades\User;

/**
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string $token
 * @property array<int, string> $scopes
 * @property string|null $oauth_client_id
 * @property string|null $oauth_client_name
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static> active()
 */
class McpToken extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'mcp_tokens';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'token',
        'scopes',
        'oauth_client_id',
        'oauth_client_name',
        'last_used_at',
        'expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Check if the token has a specific scope or wildcard access.
     */
    public function hasScope(TokenScope $scope): bool
    {
        /** @var array<int, string> $scopes */
        $scopes = $this->scopes;

        if (in_array(TokenScope::FullAccess->value, $scopes, true)) {
            return true;
        }

        return in_array($scope->value, $scopes, true);
    }

    /**
     * Check if the token has expired.
     */
    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isPast();
    }

    /**
     * Mark the token as recently used.
     */
    public function markAsUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    /**
     * Scope a query to only include active (non-expired) tokens.
     *
     * @param  Builder<static>  $query
     *
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Get the Statamic user associated with this token.
     */
    public function statamicUser(): ?StatamicUser
    {
        return User::find($this->user_id);
    }
}
