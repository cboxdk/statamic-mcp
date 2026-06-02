<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Unit\Http\Controllers\CP;

use Cboxdk\StatamicMcp\Http\Controllers\CP\Concerns\ResolvesUserId;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use ReflectionMethod;
use Statamic\Auth\Eloquent\User as EloquentUser;
use Statamic\Facades\User;

class ResolvesUserIdTest extends TestCase
{
    public function test_resolves_integer_user_ids_as_strings(): void
    {
        User::shouldReceive('current')
            ->once()
            ->andReturn($this->makeEloquentUserWithId(1));

        $this->assertSame('1', $this->resolveCurrentUserId());
    }

    public function test_preserves_string_user_ids(): void
    {
        User::shouldReceive('current')
            ->once()
            ->andReturn($this->makeEloquentUserWithId('flat-file-user'));

        $this->assertSame('flat-file-user', $this->resolveCurrentUserId());
    }

    public function test_resolves_missing_user_as_empty_string(): void
    {
        User::shouldReceive('current')
            ->once()
            ->andReturn(null);

        $this->assertSame('', $this->resolveCurrentUserId());
    }

    private function resolveCurrentUserId(): string
    {
        $controller = new class
        {
            use ResolvesUserId;
        };

        $method = new ReflectionMethod($controller, 'resolveUserId');
        $method->setAccessible(true);

        return $method->invoke($controller);
    }

    private function makeEloquentUserWithId(int|string $id): EloquentUser
    {
        $model = new class extends Model
        {
            public $timestamps = false;

            public $incrementing = false;

            protected $keyType = 'string';

            protected $guarded = [];
        };
        $model->setAttribute('id', $id);

        return (new EloquentUser)->model($model);
    }
}
