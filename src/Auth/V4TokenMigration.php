<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\OAuthMutationLock;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Support\Str;

/**
 * Brings stored tokens in line with what v4 lets them do, and back again
 * before a downgrade. It only ever narrows: a step either keeps a token,
 * drops scopes that already grant nothing, or revokes it. No scope is added,
 * so nobody gains access they were not approved for.
 *
 * Upgrade: a v3 `*` is refused by v4, and an OAuth grant carrying `*` or a
 * replaced admin `:write` must be approved again, so those are revoked. A
 * personal token keeps its live scopes and loses the replaced ones, which
 * also stops a later downgrade from reviving them. The `content:*`
 * umbrellas never granted anything and are dropped from personal tokens;
 * an OAuth client's next refresh drops them from its grant.
 *
 * Rollback: v3 lets `{entries,terms,assets}:write` publish and delete, so a
 * token v4 narrowed would grow under v3. Those are revoked before the
 * package is downgraded. A revoked token is never restored: the journal
 * keeps scopes, never secrets or hashes.
 *
 * @phpstan-type Step array{token_id: string, name: string, owner: string, oauth_client_id: string|null, action: 'revoke'|'narrow'|'keep', before: list<string>, after: list<string>|null, reason: string}
 */
final class V4TokenMigration
{
    public const UPGRADE = 'upgrade';

    public const ROLLBACK = 'rollback';

    public function __construct(
        private readonly TokenStore $tokens,
        private readonly TokenService $tokenService,
        private readonly OAuthDriver $oauth,
    ) {}

    /**
     * The steps this mode would take. Reads only.
     *
     * @param  self::UPGRADE|self::ROLLBACK  $mode
     *
     * @return list<Step>
     */
    public function plan(string $mode): array
    {
        $steps = [];
        foreach ($this->tokens->listAll() as $token) {
            $step = $mode === self::ROLLBACK ? $this->rollbackStep($token) : $this->upgradeStep($token);
            if ($step !== null) {
                $steps[] = $step;
            }
        }

        // Access credentials expire independently of consent. Inspect codes
        // and refresh tokens too, and revoke by owner/client, not a rotating id.
        $revoking = [];
        foreach ($steps as $step) {
            if ($step['action'] === 'revoke' && $step['oauth_client_id'] !== null) {
                $revoking[json_encode([$step['owner'], $step['oauth_client_id']], JSON_THROW_ON_ERROR)] = true;
            }
        }
        foreach ($this->oauth->pendingGrants() as $grant) {
            $changes = TokenScope::v3Changes($grant->scopes);
            $unsafe = $mode === self::ROLLBACK ? isset($changes['publish_delete']) : isset($changes['full_access']) || isset($changes['replaced']);
            $key = json_encode([$grant->userId, $grant->clientId], JSON_THROW_ON_ERROR);
            if (! $unsafe || isset($revoking[$key])) {
                continue;
            }
            $revoking[$key] = true;
            $steps[] = [
                'token_id' => 'oauth-' . hash('sha256', $key),
                'name' => 'OAuth grant', 'owner' => $grant->userId, 'oauth_client_id' => $grant->clientId,
                'action' => 'revoke', 'before' => $grant->scopes, 'after' => null,
                'reason' => $mode === self::ROLLBACK ? 'v3 would widen this pending grant; authorize the client again after downgrading' : 'This pending v3 grant needs fresh v4 consent',
            ];
        }

        usort($steps, static fn (array $a, array $b): int => [$a['action'], $a['name'], $a['token_id']] <=> [$b['action'], $b['name'], $b['token_id']]);

        return $steps;
    }

    /**
     * Carries out the plan's changing steps. The journal is written before
     * the first change, so a journal that cannot be written stops the run
     * with nothing changed. Running again after a partial run finds only
     * what is left.
     *
     * @param  self::UPGRADE|self::ROLLBACK  $mode
     * @param  list<Step>  $steps
     *
     * @return array{journal: string, applied: int, error: string|null}
     */
    public function apply(string $mode, array $steps): array
    {
        $changing = array_values(array_filter($steps, static fn (array $step): bool => $step['action'] !== 'keep'));
        $journal = $this->journalPath($mode);
        $record = ['schema' => 1, 'mode' => $mode, 'started_at' => now()->toIso8601String(), 'state' => 'planned', 'steps' => $changing, 'applied' => []];
        $this->writeJournal($journal, $record);

        $error = null;
        foreach ($changing as $step) {
            try {
                $this->applyStep($step);
                $record['applied'][] = $step['token_id'];
            } catch (\Throwable) {
                // Details may carry storage credentials; the journal names the token instead.
                $error = "Token {$step['token_id']} could not be changed; nothing after it was attempted. Fix the store and run the command again.";
                break;
            }
        }

        $record['state'] = $error === null ? 'completed' : 'failed';
        $record['finished_at'] = now()->toIso8601String();
        $this->writeJournal($journal, $record);

        return ['journal' => $journal, 'applied' => count($record['applied']), 'error' => $error];
    }

    /** @return Step|null */
    private function upgradeStep(McpTokenData $token): ?array
    {
        $changes = TokenScope::v3Changes($token->scopes);
        if ($changes === []) {
            return null;
        }

        $before = array_values($token->scopes);
        if (isset($changes['full_access'])) {
            return $this->step($token, 'revoke', $before, null, 'v3 full access (*) is refused; the owner creates a new token or authorizes the client again');
        }

        if (isset($changes['replaced'])) {
            $live = array_values(array_diff($before, $changes['replaced'], $changes['inert'] ?? []));
            $replaced = implode(', ', $changes['replaced']);
            if ($token->oauthClientId !== null) {
                return $this->step($token, 'revoke', $before, null, "{$replaced} was replaced by an :admin scope; the client must be authorized again");
            }
            if ($live === []) {
                return $this->step($token, 'revoke', $before, null, "{$replaced} was replaced by an :admin scope and the token holds nothing else");
            }

            return $this->step($token, 'narrow', $before, $live, "{$replaced} was replaced by an :admin scope and grants nothing; the other scopes keep working");
        }

        if (isset($changes['inert'])) {
            // The content umbrellas never granted anything, so dropping them
            // changes no access. An OAuth token is left alone: changing its
            // scopes would end the grant, and its next refresh drops them.
            $live = array_values(array_diff($before, $changes['inert']));
            $inert = implode(', ', $changes['inert']);
            if ($token->oauthClientId !== null) {
                return $this->step($token, 'keep', $before, $before, "{$inert} grants nothing; the client's next refresh drops it");
            }
            if ($live === []) {
                return $this->step($token, 'revoke', $before, null, "{$inert} grants nothing and the token holds nothing else");
            }

            return $this->step($token, 'narrow', $before, $live, "{$inert} grants nothing and is dropped; the other scopes keep working");
        }

        $missing = implode(', ', $changes['publish_delete'] ?? []);

        return $this->step($token, 'keep', $before, $before, "still edits; needs {$missing} to publish or delete (add them in Tools → MCP where they should)");
    }

    /** @return Step|null */
    private function rollbackStep(McpTokenData $token): ?array
    {
        $missing = TokenScope::v3Changes($token->scopes)['publish_delete'] ?? [];
        if ($missing === []) {
            return null;
        }

        return $this->step($token, 'revoke', array_values($token->scopes), null,
            'v3 would let it ' . implode(', ', array_map(static fn (string $scope): string => Str::after($scope, ':') . ' ' . Str::before($scope, ':'), $missing)) . ', which v4 did not grant');
    }

    /**
     * @param  'revoke'|'narrow'|'keep'  $action
     * @param  list<string>  $before
     * @param  list<string>|null  $after
     *
     * @return Step
     */
    private function step(McpTokenData $token, string $action, array $before, ?array $after, string $reason): array
    {
        return [
            'token_id' => $token->id,
            'name' => $token->name,
            'owner' => $token->userId,
            'oauth_client_id' => $token->oauthClientId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
        ];
    }

    /** @param  Step  $step */
    private function applyStep(array $step): void
    {
        if ($step['action'] === 'revoke' && $step['oauth_client_id'] !== null) {
            app(OAuthMutationLock::class)->run(function () use ($step): void {
                $this->oauth->revokeGrantsForClient($step['owner'], $step['oauth_client_id']);
                $this->tokenService->revokeOAuthTokens($step['owner'], $step['oauth_client_id']);
            });

            return;
        }

        $current = $this->tokens->find($step['token_id']);
        if ($current === null) {
            return; // Revoked meanwhile: the goal is reached.
        }

        // Act only on the token as it was planned. A token changed since
        // the plan was shown is left for the next run to plan again.
        $planned = $step['before'];
        $stored = array_values($current->scopes);
        sort($planned);
        sort($stored);
        if ($planned !== $stored) {
            throw new \RuntimeException('Token changed since planning.');
        }

        if ($step['action'] === 'narrow') {
            $this->tokenService->updateToken($step['token_id'], scopes: $step['after'] ?? [], expectedScopes: $step['before']);

            return;
        }

        if (! $this->tokenService->revokeToken($step['token_id']) && $this->tokens->find($step['token_id']) !== null) {
            throw new \RuntimeException('Token could not be revoked.');
        }
    }

    private function journalPath(string $mode): string
    {
        $directory = config('statamic.mcp.storage.migrations_path');
        $directory = is_string($directory) && $directory !== '' ? $directory : storage_path('statamic-mcp/migrations');

        return rtrim($directory, '/') . '/v4-' . $mode . '-' . now()->format('Ymd-His') . '-' . Str::lower(Str::random(6)) . '.json';
    }

    /** @param  array<string, mixed>  $record */
    private function writeJournal(string $path, array $record): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('The migration journal directory could not be created.');
        }

        $temporary = $path . '.tmp';
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($temporary, $json) !== strlen($json) || ! chmod($temporary, 0600) || ! rename($temporary, $path)) {
            @unlink($temporary);

            throw new \RuntimeException('The migration journal could not be written.');
        }
    }
}
