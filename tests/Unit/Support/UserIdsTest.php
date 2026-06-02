<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Unit\Support;

use Cboxdk\StatamicMcp\Support\UserIds;
use Cboxdk\StatamicMcp\Tests\TestCase;

class UserIdsTest extends TestCase
{
    public function test_normalizes_integer_user_ids_from_eloquent_driver(): void
    {
        $this->assertSame('1', UserIds::normalize(1));
    }

    public function test_preserves_string_user_ids_from_flat_file_driver(): void
    {
        $this->assertSame('flat-file-user', UserIds::normalize('flat-file-user'));
    }
}
