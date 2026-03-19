<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth\Cimd;

use Cboxdk\StatamicMcp\OAuth\Concerns\ValidatesRedirectUris;

/**
 * Immutable value object representing a validated CIMD metadata document.
 *
 * Parses and validates a CIMD JSON document per the MCP CIMD specification.
 * Rejects documents with prohibited fields or invalid values.
 */
final class CimdMetadata
{
    use ValidatesRedirectUris;

    /** @var list<string> */
    private const PROHIBITED_FIELDS = [
        'client_secret',
        'client_secret_expires_at',
    ];

    /** @var list<string> */
    private const PROHIBITED_AUTH_METHODS = [
        'client_secret_post',
        'client_secret_basic',
        'client_secret_jwt',
    ];

    /** @var list<string> */
    private const ALLOWED_AUTH_METHODS = [
        'none',
        'private_key_jwt',
    ];

    /**
     * @param  list<string>  $redirectUris
     * @param  list<string>|null  $contacts
     * @param  list<string>|null  $grantTypes
     * @param  list<string>|null  $responseTypes
     */
    private function __construct(
        public readonly string $clientId,
        public readonly string $clientName,
        public readonly array $redirectUris,
        public readonly ?string $clientUri,
        public readonly ?string $logoUri,
        public readonly ?string $scope,
        public readonly ?array $contacts,
        public readonly ?array $grantTypes,
        public readonly ?array $responseTypes,
        public readonly ?string $tokenEndpointAuthMethod,
    ) {}

    /**
     * Create a CimdMetadata from a decoded JSON array.
     *
     * @param  array<string, mixed>  $data  The decoded JSON document
     * @param  CimdClientId  $expectedClientId  The client_id URL used to fetch this document
     *
     * @throws CimdValidationException If the document fails validation
     */
    public static function fromArray(array $data, CimdClientId $expectedClientId): self
    {
        $instance = new self('', '', [], null, null, null, null, null, null, null);

        $instance->rejectProhibitedFields($data);
        $clientId = $instance->validateClientId($data, $expectedClientId);
        $clientName = $instance->validateClientName($data);
        $redirectUris = $instance->validateRedirectUris($data);
        $tokenEndpointAuthMethod = $instance->validateTokenEndpointAuthMethod($data);
        $contacts = $instance->validateOptionalStringArray($data, 'contacts');
        $grantTypes = $instance->validateOptionalStringArray($data, 'grant_types');
        $responseTypes = $instance->validateOptionalStringArray($data, 'response_types');

        return new self(
            clientId: $clientId,
            clientName: $clientName,
            redirectUris: $redirectUris,
            clientUri: $instance->validateOptionalString($data, 'client_uri'),
            logoUri: $instance->validateOptionalString($data, 'logo_uri'),
            scope: $instance->validateOptionalString($data, 'scope'),
            contacts: $contacts,
            grantTypes: $grantTypes,
            responseTypes: $responseTypes,
            tokenEndpointAuthMethod: $tokenEndpointAuthMethod,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CimdValidationException
     */
    private function rejectProhibitedFields(array $data): void
    {
        foreach (self::PROHIBITED_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                throw new CimdValidationException(
                    'prohibited_field',
                    "CIMD metadata must not contain the '{$field}' field.",
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CimdValidationException
     */
    private function validateClientId(array $data, CimdClientId $expectedClientId): string
    {
        if (! isset($data['client_id'])) {
            throw new CimdValidationException(
                'missing_field',
                "CIMD metadata must contain a 'client_id' field.",
            );
        }

        if (! is_string($data['client_id'])) {
            throw new CimdValidationException(
                'invalid_field',
                "CIMD metadata 'client_id' must be a string.",
            );
        }

        if ($data['client_id'] !== $expectedClientId->toString()) {
            throw new CimdValidationException(
                'client_id_mismatch',
                'CIMD metadata client_id does not match the fetch URL.',
            );
        }

        return $data['client_id'];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CimdValidationException
     */
    private function validateClientName(array $data): string
    {
        if (! isset($data['client_name'])) {
            throw new CimdValidationException(
                'missing_field',
                "CIMD metadata must contain a 'client_name' field.",
            );
        }

        if (! is_string($data['client_name'])) {
            throw new CimdValidationException(
                'invalid_field',
                "CIMD metadata 'client_name' must be a string.",
            );
        }

        if (trim($data['client_name']) === '') {
            throw new CimdValidationException(
                'invalid_field',
                "CIMD metadata 'client_name' must not be empty.",
            );
        }

        return $data['client_name'];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @return list<string>
     *
     * @throws CimdValidationException
     */
    private function validateRedirectUris(array $data): array
    {
        if (! isset($data['redirect_uris'])) {
            throw new CimdValidationException(
                'missing_field',
                "CIMD metadata must contain a 'redirect_uris' field.",
            );
        }

        if (! is_array($data['redirect_uris'])) {
            throw new CimdValidationException(
                'invalid_field',
                "CIMD metadata 'redirect_uris' must be an array.",
            );
        }

        if ($data['redirect_uris'] === []) {
            throw new CimdValidationException(
                'invalid_field',
                "CIMD metadata 'redirect_uris' must not be empty.",
            );
        }

        $uris = [];
        foreach ($data['redirect_uris'] as $uri) {
            if (! is_string($uri)) {
                throw new CimdValidationException(
                    'invalid_field',
                    "CIMD metadata 'redirect_uris' must contain only strings.",
                );
            }

            if (! $this->validateRedirectUri($uri)) {
                throw new CimdValidationException(
                    'invalid_redirect_uri',
                    "CIMD metadata redirect URI '{$uri}' is not valid. Must be HTTPS or HTTP localhost.",
                );
            }

            $uris[] = $uri;
        }

        return $uris;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CimdValidationException
     */
    private function validateTokenEndpointAuthMethod(array $data): ?string
    {
        if (! array_key_exists('token_endpoint_auth_method', $data)) {
            return null;
        }

        $method = $data['token_endpoint_auth_method'];

        if (! is_string($method)) {
            throw new CimdValidationException(
                'invalid_field',
                "CIMD metadata 'token_endpoint_auth_method' must be a string.",
            );
        }

        if (in_array($method, self::PROHIBITED_AUTH_METHODS, true)) {
            throw new CimdValidationException(
                'prohibited_auth_method',
                "CIMD metadata 'token_endpoint_auth_method' value '{$method}' is not allowed.",
            );
        }

        if (! in_array($method, self::ALLOWED_AUTH_METHODS, true)) {
            throw new CimdValidationException(
                'invalid_field',
                "CIMD metadata 'token_endpoint_auth_method' must be 'none' or 'private_key_jwt'.",
            );
        }

        return $method;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateOptionalString(array $data, string $field): ?string
    {
        if (! array_key_exists($field, $data)) {
            return null;
        }

        if (! is_string($data[$field])) {
            return null;
        }

        return $data[$field];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @return list<string>|null
     */
    private function validateOptionalStringArray(array $data, string $field): ?array
    {
        if (! array_key_exists($field, $data)) {
            return null;
        }

        if (! is_array($data[$field])) {
            return null;
        }

        $result = [];
        foreach ($data[$field] as $item) {
            if (is_string($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }
}
