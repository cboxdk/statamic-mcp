<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth\Exceptions;

class OAuthException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $description,
        int $httpStatus = 400,
    ) {
        parent::__construct($description, $httpStatus);
    }

    /** @return array{error: string, error_description: string} */
    public function toOAuthResponse(): array
    {
        return [
            'error' => $this->errorCode,
            'error_description' => $this->getMessage(),
        ];
    }
}
