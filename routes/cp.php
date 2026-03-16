<?php

use Cboxdk\StatamicMcp\Http\Controllers\CP\AuditController;
use Cboxdk\StatamicMcp\Http\Controllers\CP\ConfigController;
use Cboxdk\StatamicMcp\Http\Controllers\CP\DashboardController;
use Cboxdk\StatamicMcp\Http\Controllers\CP\TokenController;
use Illuminate\Support\Facades\Route;

// Main MCP page (user dashboard with tabs)
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// Admin MCP page (all tokens, activity, system)
Route::get('/admin', [DashboardController::class, 'admin'])->name('admin');

// Token API endpoints
Route::post('/tokens', [TokenController::class, 'store'])->name('tokens.store');
Route::put('/tokens/{token}', [TokenController::class, 'update'])->name('tokens.update');
Route::post('/tokens/{token}/regenerate', [TokenController::class, 'regenerate'])->name('tokens.regenerate');
Route::delete('/tokens/{token}', [TokenController::class, 'destroy'])->name('tokens.destroy');

// Client config endpoint
Route::get('/config/{client}', [ConfigController::class, 'show'])->name('config.show');

// Audit log endpoint
Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
