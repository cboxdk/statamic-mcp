<?php

use Cboxdk\StatamicMcp\Http\Controllers\CP\ApprovalController;
use Cboxdk\StatamicMcp\Http\Controllers\CP\AuditController;
use Cboxdk\StatamicMcp\Http\Controllers\CP\ConfigController;
use Cboxdk\StatamicMcp\Http\Controllers\CP\DashboardController;
use Cboxdk\StatamicMcp\Http\Controllers\CP\TokenController;
use Illuminate\Support\Facades\Route;
use Statamic\Http\Middleware\CP\RequireElevatedSession;

// Main MCP page (user dashboard with tabs)
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// Admin MCP page (all tokens, activity, system)
Route::get('/admin', [DashboardController::class, 'admin'])->name('admin');

// Token API endpoints
Route::post('/tokens', [TokenController::class, 'store'])->name('tokens.store');
Route::put('/tokens/{token}', [TokenController::class, 'update'])->name('tokens.update');
Route::post('/tokens/{token}/regenerate', [TokenController::class, 'regenerate'])->name('tokens.regenerate');
Route::delete('/tokens/{token}', [TokenController::class, 'destroy'])->name('tokens.destroy');

// Approvals for gated agent calls. POST only; approving needs an elevated
// session so a browser agent in the same tab cannot approve on its own.
Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve'])
    ->where('approval', '[a-f0-9]{64}')->middleware(RequireElevatedSession::class)->name('approvals.approve');
Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject'])
    ->where('approval', '[a-f0-9]{64}')->name('approvals.reject');

// Client config endpoint
Route::get('/config/{client}', [ConfigController::class, 'show'])->name('config.show');

// Audit log endpoint
Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');

// Note: OAuth authorize routes are registered separately in ServiceProvider::registerOAuthAuthorizeRoutes()
// with statamic.cp middleware (session/CSRF) but WITHOUT statamic.cp.authenticated,
// so the controller can store the intended URL before redirecting to login.
