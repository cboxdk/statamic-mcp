<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Concerns;

use Statamic\Contracts\Auth\User;
use Statamic\Facades\User as UserFacade;

/**
 * Shared helpers for creating and authenticating Statamic users in tests.
 */
trait CreatesAuthenticatedUser
{
    protected function createSuperAdmin(string $email = 'admin@test.com'): User
    {
        $user = UserFacade::make()
            ->email($email)
            ->makeSuper()
            ->set('name', 'Admin');
        $user->save();

        return $user;
    }

    protected function createRegularUser(string $email = 'user@test.com'): User
    {
        $user = UserFacade::make()
            ->email($email)
            ->set('name', 'User');
        $user->save();

        return $user;
    }

    /**
     * Create a super admin and authenticate as them.
     */
    protected function actingAsAdmin(): static
    {
        $user = $this->createSuperAdmin();
        $this->actingAs($user);

        return $this;
    }

    /**
     * Create a regular user and authenticate as them.
     */
    protected function actingAsUser(): static
    {
        $user = $this->createRegularUser();
        $this->actingAs($user);

        return $this;
    }
}
