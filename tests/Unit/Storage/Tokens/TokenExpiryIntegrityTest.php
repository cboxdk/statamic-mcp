<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Unit\Storage\Tokens;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class TokenExpiryIntegrityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/mcp-expiry-integrity-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public static function damagedExpiries(): array
    {
        return [[false], [true], [1234567890], [[]], ['tomorrow'], [''],
            ['2026-02-30T12:00:00+00:00'], ['2026-11-07'], ['missing']];
    }

    #[DataProvider('damagedExpiries')]
    public function test_damaged_expiry_cannot_authenticate_as_a_non_expiring_token(mixed $expiry): void
    {
        $store = new FileTokenStore($this->directory);
        $hash = hash('sha256', 'credential');
        $token = $store->create('user', 'Expiring access', $hash, ['entries:read'], Carbon::now()->subDay());
        $path = $this->directory . '/' . $token->id . '.yaml';
        $data = Yaml::parseFile($path);
        if ($expiry === 'missing') {
            unset($data['expires_at']);
        } else {
            $data['expires_at'] = $expiry;
        }
        $bytes = Yaml::dump($data);
        file_put_contents($path, $bytes);
        try {
            $store->findByHash($hash);
            $this->fail('Corrupt expiry must not produce an authenticatable token.');
        } catch (\UnexpectedValueException $error) {
            $this->assertStringContainsString('expiry', $error->getMessage());
        }
        $this->assertSame($bytes, file_get_contents($path));
    }

    public function test_explicit_null_still_supports_a_deliberately_non_expiring_token(): void
    {
        $store = new FileTokenStore($this->directory);
        $token = $store->create('user', 'No expiry', hash('sha256', 'credential'), ['entries:read'], null);
        $found = $store->find($token->id);
        $this->assertNull($found->expiresAt);
        $this->assertFalse($found->isExpired());
    }

    public function test_a_token_is_expired_at_the_exact_expiration_instant(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-07T12:00:00+00:00'));
        $store = new FileTokenStore($this->directory);
        $token = $store->create('user', 'Boundary', hash('sha256', 'credential'), ['entries:read'], Carbon::now());
        $this->assertTrue($store->find($token->id)->isExpired());
    }

    public function test_absolute_timezone_and_fractional_expiry_keep_their_instant(): void
    {
        $store = new FileTokenStore($this->directory);
        $token = $store->create('user', 'Absolute', hash('sha256', 'credential'), ['entries:read'], null);
        $path = $this->directory . '/' . $token->id . '.yaml';
        $data = Yaml::parseFile($path);
        foreach (['2026-11-07T13:00:00+01:00', '2026-11-07T12:00:00.123456Z'] as $expiry) {
            $data['expires_at'] = $expiry;
            file_put_contents($path, Yaml::dump($data));
            $this->assertTrue($store->find($token->id)->expiresAt->equalTo(Carbon::parse($expiry)));
        }
    }
}
