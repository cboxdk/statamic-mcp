<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Storage\Tokens;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * File-based token store that persists tokens as YAML files on disk.
 *
 * Locking strategy: individual token files are written with exclusive flock
 * (LOCK_EX) to prevent torn writes under concurrent requests. The index file
 * uses the same exclusive-lock pattern for every read-modify-write cycle.
 *
 * Atomic index rebuild: rebuildIndex() writes to a sibling .index.tmp file
 * first and then calls rename(), which is atomic on local POSIX filesystems.
 * On NFS mounts rename() is NOT guaranteed to be atomic — two concurrent
 * rebuilds may interleave. If you are running on NFS, prefer the
 * DatabaseTokenStore instead.
 */
class FileTokenStore extends BaseTokenStore implements TokenStore
{
    private readonly string $storagePath;

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
    ): McpTokenData {
        $id = (string) Str::uuid();
        $now = Carbon::now();

        $data = [
            'id' => $id,
            'user_id' => $userId,
            'name' => $name,
            'token_hash' => $tokenHash,
            'scopes' => $scopes,
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
    }

    public function findByHash(string $tokenHash): ?McpTokenData
    {
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
    }

    public function find(string $id): ?McpTokenData
    {
        $filePath = $this->tokenFilePath($id);

        if (! file_exists($filePath)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = Yaml::parseFile($filePath);

        return $this->arrayToTokenData($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data): ?McpTokenData
    {
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

        foreach ($data as $key => $value) {
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
    }

    public function delete(string $id): bool
    {
        $filePath = $this->tokenFilePath($id);

        if (! file_exists($filePath)) {
            return false;
        }

        /** @var array<string, mixed> $data */
        $data = Yaml::parseFile($filePath);
        $hash = is_string($data['token_hash'] ?? null) ? $data['token_hash'] : '';

        unlink($filePath);
        $this->removeFromIndex($hash);

        return true;
    }

    public function deleteForUser(string $userId): int
    {
        $deleted = 0;

        foreach ($this->scanAllTokens() as $data) {
            if (($data['user_id'] ?? '') === $userId) {
                $id = is_string($data['id'] ?? null) ? $data['id'] : '';
                $hash = is_string($data['token_hash'] ?? null) ? $data['token_hash'] : '';

                $filePath = $this->tokenFilePath($id);
                if (file_exists($filePath)) {
                    unlink($filePath);
                }

                $this->removeFromIndex($hash);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return Collection<int, McpTokenData>
     */
    public function listForUser(string $userId): Collection
    {
        return $this->scanAllAsData()
            ->filter(fn (McpTokenData $token): bool => $token->userId === $userId)
            ->sortByDesc(fn (McpTokenData $token): string => $token->createdAt->toIso8601String())
            ->values();
    }

    /**
     * @return Collection<int, McpTokenData>
     */
    public function listAll(): Collection
    {
        return $this->scanAllAsData()
            ->sortByDesc(fn (McpTokenData $token): string => $token->createdAt->toIso8601String())
            ->values();
    }

    public function import(McpTokenData $token): McpTokenData
    {
        $data = [
            'id' => $token->id,
            'user_id' => $token->userId,
            'name' => $token->name,
            'token_hash' => $token->tokenHash,
            'scopes' => $token->scopes,
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
    }

    public function pruneExpired(): int
    {
        $pruned = 0;

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
                    unlink($filePath);
                }

                $this->removeFromIndex($hash);
                $pruned++;
            }
        }

        return $pruned;
    }

    public function markAsUsed(string $id): void
    {
        $filePath = $this->tokenFilePath($id);

        if (! file_exists($filePath)) {
            return;
        }

        /** @var array<string, mixed> $data */
        $data = Yaml::parseFile($filePath);
        $data['last_used_at'] = Carbon::now()->toIso8601String();

        $this->writeYamlFile($id, $data);
    }

    /**
     * Write a token's YAML file with an exclusive file lock to prevent
     * torn writes when multiple requests touch the same token concurrently.
     *
     * Falls back to an unlocked write if fopen/flock is unavailable
     * (e.g. on some virtual filesystems used in tests).
     *
     * @param  array<string, mixed>  $data
     */
    private function writeYamlFile(string $id, array $data): void
    {
        $filePath = $this->tokenFilePath($id);
        $yaml = Yaml::dump($data, 2, 4, Yaml::DUMP_NULL_AS_TILDE);

        $handle = fopen($filePath, 'c');

        if ($handle !== false && flock($handle, LOCK_EX)) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $yaml);
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
        } else {
            if ($handle !== false) {
                fclose($handle);
            }

            // Fallback: unlocked write (single-process or test environments)
            file_put_contents($filePath, $yaml);
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
        if ($hash === '') {
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
        unset($index[$hash]);

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
        $files = glob($this->storagePath . '/*.yaml');

        if ($files === false) {
            return [];
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
