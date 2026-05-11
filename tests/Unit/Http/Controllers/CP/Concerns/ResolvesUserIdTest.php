<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Http\Controllers\CP\Concerns\ResolvesUserId;
use Illuminate\Contracts\Auth\Authenticatable;
use Statamic\Contracts\Auth\User as StatamicUserContract;
use Statamic\Facades\User;

/**
 * Concrete subject that exposes the private trait method for direct testing.
 */
class ResolvesUserIdSubject
{
    use ResolvesUserId {
        resolveUserId as public;
    }
}

it('returns the user id as a string when User->id() returns an int (Eloquent driver)', function () {
    $user = Mockery::mock(StatamicUserContract::class, Authenticatable::class);
    $user->shouldReceive('id')->andReturn(42);

    User::shouldReceive('current')->andReturn($user);

    $result = (new ResolvesUserIdSubject)->resolveUserId();

    expect($result)->toBe('42');
});

it('returns the user id as a string when User->id() returns a string (file driver)', function () {
    $user = Mockery::mock(StatamicUserContract::class, Authenticatable::class);
    $user->shouldReceive('id')->andReturn('019357a0-7f6a-7000-a1b2-deadbeef0000');

    User::shouldReceive('current')->andReturn($user);

    $result = (new ResolvesUserIdSubject)->resolveUserId();

    expect($result)->toBe('019357a0-7f6a-7000-a1b2-deadbeef0000');
});

it('returns an empty string when no user is authenticated', function () {
    User::shouldReceive('current')->andReturn(null);

    $result = (new ResolvesUserIdSubject)->resolveUserId();

    expect($result)->toBe('');
});
