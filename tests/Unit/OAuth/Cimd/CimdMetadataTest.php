<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\OAuth\Cimd\CimdClientId;
use Cboxdk\StatamicMcp\OAuth\Cimd\CimdMetadata;
use Cboxdk\StatamicMcp\OAuth\Cimd\CimdValidationException;

// --- Helper ---

function validClientId(): CimdClientId
{
    $id = CimdClientId::tryFrom('https://app.example.com/oauth/metadata.json');
    assert($id !== null);

    return $id;
}

/**
 * @return array<string, mixed>
 */
function validMetadataArray(): array
{
    return [
        'client_id' => 'https://app.example.com/oauth/metadata.json',
        'client_name' => 'My Test App',
        'redirect_uris' => ['https://app.example.com/callback'],
    ];
}

// --- Valid parsing ---

it('parses valid metadata with required fields only', function (): void {
    $metadata = CimdMetadata::fromArray(validMetadataArray(), validClientId());

    expect($metadata->clientId)->toBe('https://app.example.com/oauth/metadata.json')
        ->and($metadata->clientName)->toBe('My Test App')
        ->and($metadata->redirectUris)->toBe(['https://app.example.com/callback'])
        ->and($metadata->clientUri)->toBeNull()
        ->and($metadata->logoUri)->toBeNull()
        ->and($metadata->scope)->toBeNull()
        ->and($metadata->contacts)->toBeNull()
        ->and($metadata->grantTypes)->toBeNull()
        ->and($metadata->responseTypes)->toBeNull()
        ->and($metadata->tokenEndpointAuthMethod)->toBeNull();
});

it('parses valid metadata with all optional fields', function (): void {
    $data = array_merge(validMetadataArray(), [
        'client_uri' => 'https://app.example.com',
        'logo_uri' => 'https://app.example.com/logo.png',
        'scope' => 'read write',
        'contacts' => ['admin@example.com'],
        'grant_types' => ['authorization_code'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $metadata = CimdMetadata::fromArray($data, validClientId());

    expect($metadata->clientUri)->toBe('https://app.example.com')
        ->and($metadata->logoUri)->toBe('https://app.example.com/logo.png')
        ->and($metadata->scope)->toBe('read write')
        ->and($metadata->contacts)->toBe(['admin@example.com'])
        ->and($metadata->grantTypes)->toBe(['authorization_code'])
        ->and($metadata->responseTypes)->toBe(['code'])
        ->and($metadata->tokenEndpointAuthMethod)->toBe('none');
});

it('accepts token_endpoint_auth_method of none', function (): void {
    $data = array_merge(validMetadataArray(), [
        'token_endpoint_auth_method' => 'none',
    ]);

    $metadata = CimdMetadata::fromArray($data, validClientId());

    expect($metadata->tokenEndpointAuthMethod)->toBe('none');
});

it('accepts token_endpoint_auth_method of private_key_jwt', function (): void {
    $data = array_merge(validMetadataArray(), [
        'token_endpoint_auth_method' => 'private_key_jwt',
    ]);

    $metadata = CimdMetadata::fromArray($data, validClientId());

    expect($metadata->tokenEndpointAuthMethod)->toBe('private_key_jwt');
});

it('accepts multiple redirect URIs', function (): void {
    $data = array_merge(validMetadataArray(), [
        'redirect_uris' => [
            'https://app.example.com/callback',
            'http://localhost:3000/callback',
        ],
    ]);

    $metadata = CimdMetadata::fromArray($data, validClientId());

    expect($metadata->redirectUris)->toHaveCount(2);
});

it('accepts HTTP localhost redirect URIs', function (): void {
    $data = array_merge(validMetadataArray(), [
        'redirect_uris' => ['http://localhost/callback'],
    ]);

    $metadata = CimdMetadata::fromArray($data, validClientId());

    expect($metadata->redirectUris)->toBe(['http://localhost/callback']);
});

it('accepts HTTP 127.0.0.1 redirect URIs', function (): void {
    $data = array_merge(validMetadataArray(), [
        'redirect_uris' => ['http://127.0.0.1:8080/callback'],
    ]);

    $metadata = CimdMetadata::fromArray($data, validClientId());

    expect($metadata->redirectUris)->toBe(['http://127.0.0.1:8080/callback']);
});

// --- client_id validation ---

it('throws when client_id is missing', function (): void {
    $data = validMetadataArray();
    unset($data['client_id']);

    CimdMetadata::fromArray($data, validClientId());
})->throws(CimdValidationException::class, "must contain a 'client_id' field");

it('throws when client_id does not match fetch URL', function (): void {
    $data = validMetadataArray();
    $data['client_id'] = 'https://evil.example.com/oauth/metadata.json';

    CimdMetadata::fromArray($data, validClientId());
})->throws(CimdValidationException::class, 'does not match the fetch URL');

it('sets client_id_mismatch error code when client_id does not match', function (): void {
    $data = validMetadataArray();
    $data['client_id'] = 'https://evil.example.com/oauth/metadata.json';

    try {
        CimdMetadata::fromArray($data, validClientId());
        test()->fail('Expected CimdValidationException');
    } catch (CimdValidationException $e) {
        expect($e->errorCode)->toBe('client_id_mismatch');
    }
});

it('throws when client_id is not a string', function (): void {
    $data = validMetadataArray();
    $data['client_id'] = 42;

    CimdMetadata::fromArray($data, validClientId());
})->throws(CimdValidationException::class, "'client_id' must be a string");

// --- client_name validation ---

it('throws when client_name is missing', function (): void {
    $data = validMetadataArray();
    unset($data['client_name']);

    CimdMetadata::fromArray($data, validClientId());
})->throws(CimdValidationException::class, "must contain a 'client_name' field");

it('throws when client_name is empty', function (): void {
    $data = validMetadataArray();
    $data['client_name'] = '';

    CimdMetadata::fromArray($data, validClientId());
})->throws(CimdValidationException::class, "'client_name' must not be empty");

it('throws when client_name is whitespace only', function (): void {
    $data = validMetadataArray();
    $data['client_name'] = '   ';

    CimdMetadata::fromArray($data, validClientId());
})->throws(CimdValidationException::class, "'client_name' must not be empty");

it('throws when client_name is not a string', function (): void {
    $data = validMetadataArray();
    $data['client_name'] = 123;

    CimdMetadata::fromArray($data, validClientId());
})->throws(CimdValidationException::class, "'client_name' must be a string");

// --- redirect_uris validation ---

it('throws when redirect_uris is missing', function (): void {
    $data = validMetadataArray();
    unset($data['redirect_uris']);

    CimdMetadata::fromArray($data, validClientId());
})->throws(CimdValidationException::class, "must contain a 'redirect_uris' field");

it('throws when redirect_uris is empty array', function (): void {
    $data = validMetadataArray();
    $data['redirect_uris'] = [];

    CimdMetadata::fromArray($data, validClientId());
})->throws(CimdValidationException::class, "'redirect_uris' must not be empty");

it('throws when redirect_uris is not an array', function (): void {
    $data = validMetadataArray();
    $data['redirect_uris'] = 'https://example.com/callback';

    CimdMetadata::fromArray($data, validClientId());
})->throws(CimdValidationException::class, "'redirect_uris' must be an array");

it('throws when redirect_uris contains non-string values', function (): void {
    $data = validMetadataArray();
    $data['redirect_uris'] = [123, 456];

    CimdMetadata::fromArray($data, validClientId());
})->throws(CimdValidationException::class, "'redirect_uris' must contain only strings");

it('throws when redirect URI is plain HTTP (not localhost)', function (): void {
    $data = validMetadataArray();
    $data['redirect_uris'] = ['http://app.example.com/callback'];

    CimdMetadata::fromArray($data, validClientId());
})->throws(CimdValidationException::class, 'is not valid');

it('throws when redirect URI has a fragment', function (): void {
    $data = validMetadataArray();
    $data['redirect_uris'] = ['https://app.example.com/callback#frag'];

    CimdMetadata::fromArray($data, validClientId());
})->throws(CimdValidationException::class, 'is not valid');

// --- Prohibited fields ---

it('throws when document contains client_secret', function (): void {
    $data = array_merge(validMetadataArray(), [
        'client_secret' => 'super-secret',
    ]);

    CimdMetadata::fromArray($data, validClientId());
})->throws(CimdValidationException::class, "'client_secret' field");

it('throws when document contains client_secret_expires_at', function (): void {
    $data = array_merge(validMetadataArray(), [
        'client_secret_expires_at' => 1234567890,
    ]);

    CimdMetadata::fromArray($data, validClientId());
})->throws(CimdValidationException::class, "'client_secret_expires_at' field");

it('sets prohibited_field error code for client_secret', function (): void {
    $data = array_merge(validMetadataArray(), [
        'client_secret' => 'secret',
    ]);

    try {
        CimdMetadata::fromArray($data, validClientId());
        test()->fail('Expected CimdValidationException');
    } catch (CimdValidationException $e) {
        expect($e->errorCode)->toBe('prohibited_field');
    }
});

// --- Prohibited auth methods ---

it('throws when token_endpoint_auth_method is client_secret_post', function (): void {
    $data = array_merge(validMetadataArray(), [
        'token_endpoint_auth_method' => 'client_secret_post',
    ]);

    CimdMetadata::fromArray($data, validClientId());
})->throws(CimdValidationException::class, "'client_secret_post' is not allowed");

it('throws when token_endpoint_auth_method is client_secret_basic', function (): void {
    $data = array_merge(validMetadataArray(), [
        'token_endpoint_auth_method' => 'client_secret_basic',
    ]);

    CimdMetadata::fromArray($data, validClientId());
})->throws(CimdValidationException::class, "'client_secret_basic' is not allowed");

it('throws when token_endpoint_auth_method is client_secret_jwt', function (): void {
    $data = array_merge(validMetadataArray(), [
        'token_endpoint_auth_method' => 'client_secret_jwt',
    ]);

    CimdMetadata::fromArray($data, validClientId());
})->throws(CimdValidationException::class, "'client_secret_jwt' is not allowed");

it('sets prohibited_auth_method error code for secret-based methods', function (): void {
    $data = array_merge(validMetadataArray(), [
        'token_endpoint_auth_method' => 'client_secret_post',
    ]);

    try {
        CimdMetadata::fromArray($data, validClientId());
        test()->fail('Expected CimdValidationException');
    } catch (CimdValidationException $e) {
        expect($e->errorCode)->toBe('prohibited_auth_method');
    }
});

// --- Optional fields ---

it('returns null for optional fields when absent', function (): void {
    $metadata = CimdMetadata::fromArray(validMetadataArray(), validClientId());

    expect($metadata->clientUri)->toBeNull()
        ->and($metadata->logoUri)->toBeNull()
        ->and($metadata->scope)->toBeNull()
        ->and($metadata->contacts)->toBeNull()
        ->and($metadata->grantTypes)->toBeNull()
        ->and($metadata->responseTypes)->toBeNull()
        ->and($metadata->tokenEndpointAuthMethod)->toBeNull();
});

it('stores optional string fields when present', function (): void {
    $data = array_merge(validMetadataArray(), [
        'client_uri' => 'https://app.example.com',
        'logo_uri' => 'https://app.example.com/logo.png',
        'scope' => 'openid profile',
    ]);

    $metadata = CimdMetadata::fromArray($data, validClientId());

    expect($metadata->clientUri)->toBe('https://app.example.com')
        ->and($metadata->logoUri)->toBe('https://app.example.com/logo.png')
        ->and($metadata->scope)->toBe('openid profile');
});

it('stores optional array fields when present', function (): void {
    $data = array_merge(validMetadataArray(), [
        'contacts' => ['admin@example.com', 'dev@example.com'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
    ]);

    $metadata = CimdMetadata::fromArray($data, validClientId());

    expect($metadata->contacts)->toBe(['admin@example.com', 'dev@example.com'])
        ->and($metadata->grantTypes)->toBe(['authorization_code', 'refresh_token'])
        ->and($metadata->responseTypes)->toBe(['code']);
});
