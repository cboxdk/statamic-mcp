<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions;

enum ActionEffect: string
{
    case Read = 'read';
    case Edit = 'edit';
    case Publish = 'publish';
    case Delete = 'delete';
    case Admin = 'admin';
}
