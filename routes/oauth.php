<?php

use Cboxdk\StatamicMcp\Http\Controllers\OAuth\DiscoveryController;
use Cboxdk\StatamicMcp\Http\Controllers\OAuth\OAuthTokenController;
use Cboxdk\StatamicMcp\Http\Controllers\OAuth\RegistrationController;
use Cboxdk\StatamicMcp\Http\Controllers\OAuth\RevocationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| OAuth Discovery & Registration Routes
|--------------------------------------------------------------------------
|
| These routes implement the OAuth 2.1 discovery metadata and dynamic
| client registration endpoints required by the MCP specification.
|
*/

// Discovery endpoints (must be at root level per RFC 8414 / RFC 9728)
Route::get('/.well-known/oauth-protected-resource', [DiscoveryController::class, 'protectedResource'])->name('mcp.oauth.protected-resource');
Route::get('/.well-known/oauth-authorization-server', [DiscoveryController::class, 'authorizationServer'])->name('mcp.oauth.authorization-server');

// Dynamic Client Registration (RFC 7591)
Route::post('/mcp/oauth/register', [RegistrationController::class, 'store'])->middleware('throttle:3,60');

// Token Exchange (OAuth 2.1 authorization_code grant with PKCE)
Route::post('/mcp/oauth/token', [OAuthTokenController::class, 'store'])->middleware('throttle:20,1');

// Token Revocation (RFC 7009)
Route::post('/mcp/oauth/revoke', [RevocationController::class, 'revoke'])->middleware('throttle:20,1');

// Authorization endpoints are registered in ServiceProvider::registerOAuthAuthorizeRoutes()
// under CP prefix with statamic.cp middleware (but without statamic.cp.authenticated)
// so the controller can store the intended URL before redirecting to login.
