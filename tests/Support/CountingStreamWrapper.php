<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Support;

/**
 * A stream wrapper that proxies every call to the real filesystem and counts
 * the writes it sees, per file.
 *
 * Exists because the property worth protecting in the token store is an
 * algorithmic one — removing N tokens rewrites the index once, not N times —
 * and that cannot be measured with a clock. Wall-clock is at the mercy of
 * whatever else the machine is doing, and even CPU time could not separate the
 * two implementations here: the linear cost of scanning and unlinking N token
 * files dwarfs the index writes, so reintroducing the quadratic bug moved the
 * measured ratio only from 1.35x to 2.12x.
 *
 * Counting the writes instead is exact, is unaffected by load, and states the
 * invariant directly.
 *
 * Register with `CountingStreamWrapper::register()`, then hand a path under
 * the `counted://` scheme to the code under test.
 */
class CountingStreamWrapper
{
    public const SCHEME = 'counted';

    /** @var array<string, int> Writes seen per real path. */
    public static array $writes = [];

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $handle;

    /** @var resource|null */
    private $dir;

    private string $path = '';

    public static function register(): void
    {
        self::unregister();
        stream_wrapper_register(self::SCHEME, self::class);
    }

    public static function unregister(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    public static function reset(): void
    {
        self::$writes = [];
    }

    /**
     * Writes recorded against a path ending in the given suffix.
     */
    public static function writesTo(string $suffix): int
    {
        $total = 0;

        foreach (self::$writes as $path => $count) {
            if (str_ends_with($path, $suffix)) {
                $total += $count;
            }
        }

        return $total;
    }

    /** Strip the scheme so the call can be forwarded to the real filesystem. */
    private static function real(string $path): string
    {
        return (string) preg_replace('#^' . self::SCHEME . '://#', '', $path);
    }

    /**
     * Run a filesystem call with this wrapper unregistered.
     *
     * Without this the forwarded call would re-enter the wrapper and recurse.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     *
     * @return T
     */
    private static function unwrapped(callable $callback): mixed
    {
        self::unregister();

        try {
            return $callback();
        } finally {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->path = self::real($path);

        $handle = self::unwrapped(fn () => @fopen($this->path, $mode));

        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function stream_write(string $data): int
    {
        self::$writes[$this->path] = (self::$writes[$this->path] ?? 0) + 1;

        return $this->handle !== null ? (int) fwrite($this->handle, $data) : 0;
    }

    public function stream_read(int $count): string
    {
        return $this->handle !== null ? (string) fread($this->handle, $count) : '';
    }

    public function stream_eof(): bool
    {
        return $this->handle === null || feof($this->handle);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return $this->handle !== null && fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_tell(): int
    {
        return $this->handle !== null ? (int) ftell($this->handle) : 0;
    }

    public function stream_truncate(int $newSize): bool
    {
        return $this->handle !== null && ftruncate($this->handle, $newSize);
    }

    public function stream_flush(): bool
    {
        return $this->handle !== null && fflush($this->handle);
    }

    public function stream_lock(int $operation): bool
    {
        // Single-process tests; real locking would need the underlying handle
        // and adds nothing here.
        return true;
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return $this->handle !== null ? fstat($this->handle) : false;
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        $real = self::real($path);

        return self::unwrapped(fn () => ($flags & STREAM_URL_STAT_LINK) ? @lstat($real) : @stat($real));
    }

    public function unlink(string $path): bool
    {
        $real = self::real($path);

        return self::unwrapped(fn () => @unlink($real));
    }

    public function rename(string $from, string $to): bool
    {
        $realFrom = self::real($from);
        $realTo = self::real($to);

        return self::unwrapped(fn () => @rename($realFrom, $realTo));
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        $real = self::real($path);
        $recursive = (bool) ($options & STREAM_MKDIR_RECURSIVE);

        return self::unwrapped(fn () => @mkdir($real, $mode, $recursive));
    }

    public function rmdir(string $path, int $options): bool
    {
        $real = self::real($path);

        return self::unwrapped(fn () => @rmdir($real));
    }

    public function dir_opendir(string $path, int $options): bool
    {
        $real = self::real($path);

        $dir = self::unwrapped(fn () => @opendir($real));

        if ($dir === false) {
            return false;
        }

        $this->dir = $dir;

        return true;
    }

    public function dir_readdir(): string|false
    {
        return $this->dir !== null ? readdir($this->dir) : false;
    }

    public function dir_rewinddir(): bool
    {
        if ($this->dir === null) {
            return false;
        }

        rewinddir($this->dir);

        return true;
    }

    public function dir_closedir(): bool
    {
        if ($this->dir !== null) {
            closedir($this->dir);
            $this->dir = null;
        }

        return true;
    }
}
