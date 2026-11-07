<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Storage\Tokens;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Actions\CapabilitySnapshot;
use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Single-node token storage. A permanent store lock protects complete read/modify/
 * write operations, including last-used updates, revocation and index rebuilding.
 * Token files are replaced atomically so a failed write preserves the old record.
 * Use DatabaseTokenStore with shared SQL for multi-node deployments.
 */
class FileTokenStore extends BaseTokenStore implements TokenStore
{
    private readonly string $storagePath;

    private bool $locked = false;

    public function __construct(?string $storagePath = null)
    {
        if ($storagePath === null) {
            /** @var string $default */
            $default = config('statamic.mcp.storage.tokens_path', storage_path('statamic-mcp/tokens'));
            $this->storagePath = $default;
        } else {
            $this->storagePath = $storagePath;
        }

        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0700, true);
        }
    }

    /**
     * @param  array<int, string>  $scopes
     */
    public function create(
        string $userId,
        string $name,
        string $tokenHash,
        array $scopes,
        ?Carbon $expiresAt,
        ?string $oauthClientId = null,
        ?string $oauthClientName = null,
        ?CapabilitySnapshot $capabilities = null,
    ): McpTokenData {
        return $this->withLock(function () use ($userId, $name, $tokenHash, $scopes, $expiresAt, $oauthClientId, $oauthClientName, $capabilities): McpTokenData {
            $id = (string) Str::uuid();
            $now = Carbon::now();

            $data = [
                'id' => $id,
                'user_id' => $userId,
                'name' => $name,
                'token_hash' => $tokenHash,
                'scopes' => $scopes,
                'capabilities' => $capabilities?->toStored(),
                'oauth_client_id' => $oauthClientId,
                'oauth_client_name' => $oauthClientName,
                'last_used_at' => null,
                'expires_at' => $expiresAt?->toIso8601String(),
                'created_at' => $now->toIso8601String(),
                'updated_at' => null,
            ];

            $this->writeYamlFile($id, $data);
            $this->updateIndex($tokenHash, $id);

            return $this->arrayToTokenData($data);
        });
    }

    public function findByHash(string $tokenHash): ?McpTokenData
    {
        return $this->withLock(function () use ($tokenHash): ?McpTokenData {
            $index = $this->readIndex();

            if (! isset($index[$tokenHash])) {
                // Index might be stale or missing, rebuild
                $this->rebuildIndex();
                $index = $this->readIndex();

                if (! isset($index[$tokenHash])) {
                    return null;
                }
            }

            /** @var string $id */
            $id = $index[$tokenHash];

            return $this->find($id);
        });
    }

    public function find(string $id): ?McpTokenData
    {
        return $this->withLock(function () use ($id): ?McpTokenData {
            $filePath = $this->tokenFilePath($id);

            if (! file_exists($filePath)) {
                return null;
            }

            /** @var array<string, mixed> $data */
            $data = Yaml::parseFile($filePath);

            return $this->arrayToTokenData($data);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data): ?McpTokenData
    {
        return $this->withLock(function () use ($id, $data): ?McpTokenData {
            $filePath = $this->tokenFilePath($id);

            if (! file_exists($filePath)) {
                return null;
            }

            /** @var array<string, mixed> $existing */
            $existing = Yaml::parseFile($filePath);

            $oldHash = is_string($existing['token_hash'] ?? null) ? $existing['token_hash'] : '';

            // Map camelCase keys to snake_case YAML keys
            $keyMap = [
                'tokenHash' => 'token_hash',
                'expiresAt' => 'expires_at',
                'lastUsedAt' => 'last_used_at',
            ];

            if (array_key_exists('scopes', $data) && $this->scopesChanged($data['scopes'], $existing['scopes'] ?? [])) {
                $existing['capabilities'] = null;
            }
            foreach ($data as $key => $value) {
                if (! in_array($key, ['name', 'scopes', 'tokenHash', 'expiresAt', 'lastUsedAt'], true)) {
                    continue;
                }
                $yamlKey = $keyMap[$key] ?? $key;

                if ($value instanceof Carbon) {
                    $existing[$yamlKey] = $value->toIso8601String();
                } elseif ($yamlKey === 'expires_at' && $value === null) {
                    $existing[$yamlKey] = null;
                } else {
                    $existing[$yamlKey] = $value;
                }
            }

            $existing['updated_at'] = Carbon::now()->toIso8601String();

            $this->writeYamlFile($id, $existing);

            // Update index if token hash changed
            $newHash = is_string($existing['token_hash'] ?? null) ? $existing['token_hash'] : '';

            if (isset($data['tokenHash']) && $oldHash !== $newHash) {
                $this->removeFromIndex($oldHash);
                $this->updateIndex($newHash, $id);
            }

            return $this->arrayToTokenData($existing);
        });
    }

    public function delete(string $id): bool
    {
        return $this->withLock(function () use ($id): bool {
            $filePath = $this->tokenFilePath($id);

            if (! file_exists($filePath)) {
                return false;
            }

            /** @var array<string, mixed> $data */
            $data = Yaml::parseFile($filePath);
            $hash = is_string($data['token_hash'] ?? null) ? $data['token_hash'] : '';

            if (! unlink($filePath)) {
                throw new \RuntimeException('Cannot revoke token.');
            }
            $this->removeFromIndex($hash);

            return true;
        });
    }

    public function deleteForUser(string $userId): int
    {
        return $this->withLock(function () use ($userId): int {
            $deleted = 0;
            /** @var list<string> $removedHashes */
            $removedHashes = [];

            foreach ($this->scanAllTokens() as $data) {
                if (($data['user_id'] ?? '') === $userId) {
                    $id = is_string($data['id'] ?? null) ? $data['id'] : '';
                    $hash = is_string($data['token_hash'] ?? null) ? $data['token_hash'] : '';

                    $filePath = $this->tokenFilePath($id);
                    if (file_exists($filePath)) {
                        if (! unlink($filePath)) {
                            throw new \RuntimeException('Cannot remove token.');
                        }
                    }

                    $removedHashes[] = $hash;
                    $deleted++;
                }
            }

            $this->removeManyFromIndex($removedHashes);

            return $deleted;
        });
    }

    /**
     * @return Collection<int, McpTokenData>
     */
    public function listForUser(string $userId): Collection
    {
        return $this->withLock(function () use ($userId): Collection {
            return $this->scanAllAsData()
                ->filter(fn (McpTokenData $token): bool => $token->userId === $userId)
                ->sortByDesc(fn (McpTokenData $token): string => $token->createdAt->toIso8601String())
                ->values();
        });
    }

    /**
     * @return Collection<int, McpTokenData>
     */
    public function listAll(): Collection
    {
        return $this->withLock(function (): Collection {
            return $this->scanAllAsData()
                ->sortByDesc(fn (McpTokenData $token): string => $token->createdAt->toIso8601String())
                ->values();
        });
    }

    public function import(McpTokenData $token): McpTokenData
    {
        return $this->withLock(function () use ($token): McpTokenData {
            $data = [
                'id' => $token->id,
                'user_id' => $token->userId,
                'name' => $token->name,
                'token_hash' => $token->tokenHash,
                'scopes' => $token->scopes,
                'capabilities' => $token->capabilities?->toStored(),
                'oauth_client_id' => $token->oauthClientId,
                'oauth_client_name' => $token->oauthClientName,
                'last_used_at' => $token->lastUsedAt?->toIso8601String(),
                'expires_at' => $token->expiresAt?->toIso8601String(),
                'created_at' => $token->createdAt->toIso8601String(),
                'updated_at' => $token->updatedAt?->toIso8601String(),
            ];

            $this->writeYamlFile($token->id, $data);
            $this->updateIndex($token->tokenHash, $token->id);

            return $this->arrayToTokenData($data);
        });
    }

    public function pruneExpired(): int
    {
        return $this->withLock(function (): int {
            $pruned = 0;
            /** @var list<string> $removedHashes */
            $removedHashes = [];

            foreach ($this->scanAllTokens() as $data) {
                $expiresAt = $data['expires_at'] ?? null;

                if ($expiresAt === null) {
                    continue;
                }

                if (! is_string($expiresAt)) {
                    continue;
                }

                $expiry = Carbon::parse($expiresAt);

                if ($expiry->isPast()) {
                    $id = is_string($data['id'] ?? null) ? $data['id'] : '';
                    $hash = is_string($data['token_hash'] ?? null) ? $data['token_hash'] : '';

                    $filePath = $this->tokenFilePath($id);
                    if (file_exists($filePath)) {
                        if (! unlink($filePath)) {
                            throw new \RuntimeException('Cannot remove token.');
                        }
                    }

                    $removedHashes[] = $hash;
                    $pruned++;
                }
            }

            $this->removeManyFromIndex($removedHashes);

            return $pruned;
        });
    }

    public function markAsUsed(string $id): void
    {
        $this->withLock(function () use ($id): void {
            $filePath = $this->tokenFilePath($id);

            if (! file_exists($filePath)) {
                return;
            }

            /** @var array<string, mixed> $data */
            $data = Yaml::parseFile($filePath);
            $data['last_used_at'] = Carbon::now()->toIso8601String();

            $this->writeYamlFile($id, $data);
        });
    }

    /** @param array<string, mixed> $data */
    private function writeYamlFile(string $id, array $data): void
    {
        $filePath = $this->tokenFilePath($id);
        $yaml = Yaml::dump($data, 2, 4, Yaml::DUMP_NULL_AS_TILDE);
        $temporary = $this->storagePath . '/.token.tmp';
        if (is_link($temporary)) {
            throw new \RuntimeException('Invalid token temporary file.');
        }
        $handle = fopen($temporary, 'w+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create token temporary file.');
        }
        try {
            if (! chmod($temporary, 0600) || fwrite($handle, $yaml) !== strlen($yaml) || ! fflush($handle)) {
                throw new \RuntimeException('Cannot persist token record.');
            }
            if (! rename($temporary, $filePath)) {
                throw new \RuntimeException('Cannot replace token record.');
            }
        } finally {
            fclose($handle);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @template T
     * @param  callable(): T  $callback
     *
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        if ($this->locked) {
            return $callback();
        }
        $handle = fopen($this->storagePath . '/.store.lock', 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open token store lock.');
        }
        try {
            $deadline = hrtime(true) + 5_000_000_000;
            while (! flock($handle, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) >= $deadline) {
                    throw new \RuntimeException('Token storage is busy.');
                }
                usleep(10000);
            }
            $this->locked = true;
            // Other workers may have renamed or removed a previously statted file.
            clearstatcache();

            return $callback();
        } finally {
            $this->locked = false;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Validate that an ID is safe for use as a filename component.
     *
     * Prevents path traversal attacks by rejecting IDs containing
     * directory separators, null bytes, or special directory names.
     *
     * @throws \InvalidArgumentException
     */
    private function sanitizeId(string $id): string
    {
        if ($id !== basename($id) || str_contains($id, "\x00") || $id === '' || $id === '.' || $id === '..') {
            throw new \InvalidArgumentException('Invalid token identifier');
        }

        return $id;
    }

    private function tokenFilePath(string $id): string
    {
        return $this->storagePath . '/' . $this->sanitizeId($id) . '.yaml';
    }

    private function indexFilePath(): string
    {
        return $this->storagePath . '/.index';
    }

    /**
     * @return array<string, string>
     */
    private function readIndex(): array
    {
        $indexPath = $this->indexFilePath();

        if (! file_exists($indexPath)) {
            return [];
        }

        $content = file_get_contents($indexPath);

        if ($content === false || $content === '') {
            return [];
        }

        /** @var array<string, string>|null $decoded */
        $decoded = json_decode($content, true);

        return $decoded ?? [];
    }

    private function updateIndex(string $hash, string $id): void
    {
        $indexPath = $this->indexFilePath();
        $handle = fopen($indexPath, 'c+');

        if ($handle === false) {
            return;
        }

        flock($handle, LOCK_EX);

        $content = '';
        $stat = fstat($handle);
        $size = $stat !== false ? $stat['size'] : 0;

        if ($size > 0) {
            rewind($handle);
            $readContent = fread($handle, $size);
            $content = $readContent !== false ? $readContent : '';
        }

        /** @var array<string, string> $index */
        $index = ($content !== '') ? (json_decode($content, true) ?? []) : [];
        $index[$hash] = $id;

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($index, JSON_PRETTY_PRINT));
        fflush($handle);

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function removeFromIndex(string $hash): void
    {
        $this->removeManyFromIndex([$hash]);
    }

    /**
     * Drop several hashes from the index in a single locked read-modify-write.
     *
     * Removing them one at a time re-read, re-encoded and rewrote the whole
     * index per token, so pruning or deleting n tokens wrote on the order of
     * n^2 bytes. On a large token store that dominated the cost of the
     * operation, and it is what made the prune scaling test intermittently
     * exceed its linear-ish budget on slower disks.
     *
     * Callers unlink the token files first and remove the hashes once at the
     * end, so a failure part-way through can leave index entries whose file is
     * already gone. That is benign: findByHash() resolves an entry through
     * find(), which requires the file, so a deleted token can never be
     * authenticated by a stale entry, and rebuildIndex() drops it on the next
     * miss.
     *
     * @param  list<string>  $hashes
     */
    private function removeManyFromIndex(array $hashes): void
    {
        $hashes = array_values(array_filter($hashes, static fn (string $hash): bool => $hash !== ''));

        if ($hashes === []) {
            return;
        }

        $indexPath = $this->indexFilePath();

        if (! file_exists($indexPath)) {
            return;
        }

        $handle = fopen($indexPath, 'c+');

        if ($handle === false) {
            return;
        }

        flock($handle, LOCK_EX);

        $content = '';
        $stat = fstat($handle);
        $size = $stat !== false ? $stat['size'] : 0;

        if ($size > 0) {
            rewind($handle);
            $readContent = fread($handle, $size);
            $content = $readContent !== false ? $readContent : '';
        }

        /** @var array<string, string> $index */
        $index = ($content !== '') ? (json_decode($content, true) ?? []) : [];

        foreach ($hashes as $hash) {
            unset($index[$hash]);
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($index, JSON_PRETTY_PRINT));
        fflush($handle);

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function rebuildIndex(): void
    {
        /** @var array<string, string> $index */
        $index = [];

        foreach ($this->scanAllTokens() as $data) {
            $hash = is_string($data['token_hash'] ?? null) ? $data['token_hash'] : '';
            $id = is_string($data['id'] ?? null) ? $data['id'] : '';

            if ($hash !== '' && $id !== '') {
                $index[$hash] = $id;
            }
        }

        // Write to a temporary file then rename for an atomic replacement.
        // rename() is atomic on local POSIX filesystems; readers that open
        // .index between the write and the rename will see either the old or
        // the new complete file — never a partial write.
        $tmpPath = $this->indexFilePath() . '.tmp';
        $indexPath = $this->indexFilePath();

        $handle = fopen($tmpPath, 'w');

        if ($handle === false) {
            return;
        }

        flock($handle, LOCK_EX);
        fwrite($handle, (string) json_encode($index, JSON_PRETTY_PRINT));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        rename($tmpPath, $indexPath);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scanAllTokens(): array
    {
        $tokens = [];

        // scandir() rather than glob(): glob() bypasses stream wrappers
        // entirely, which silently returns nothing when the store is pointed at
        // a wrapped path. Same ordering (both sort ascending), same result.
        $entries = @scandir($this->storagePath);

        if ($entries === false) {
            return [];
        }

        $files = [];

        foreach ($entries as $entry) {
            if (str_ends_with($entry, '.yaml')) {
                $files[] = $this->storagePath . '/' . $entry;
            }
        }

        foreach ($files as $file) {
            /** @var array<string, mixed>|null $data */
            $data = Yaml::parseFile($file);

            if (is_array($data) && isset($data['id'])) {
                $tokens[] = $data;
            }
        }

        return $tokens;
    }

    /**
     * @return Collection<int, McpTokenData>
     */
    private function scanAllAsData(): Collection
    {
        return collect($this->scanAllTokens())
            ->map(fn (array $data): McpTokenData => $this->arrayToTokenData($data));
    }
}
