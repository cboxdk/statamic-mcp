<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

/**
 * A token's expiry cannot be changed as asked. The message is written for the
 * person editing the token and is safe to show them.
 */
final class TokenExpiryRefused extends \DomainException {}
