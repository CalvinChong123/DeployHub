<?php

use App\Http\Controllers\AutomationController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\HubController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));
Route::middleware('auth')->group(function () {
    Route::get('/two-factor-challenge', [SecurityController::class, 'challenge'])->name('mfa.challenge');
    Route::post('/two-factor-challenge', [SecurityController::class, 'authenticate'])->middleware('throttle:5,1');
});
Route::middleware(['auth', 'workspace'])->group(function () {
    Route::get('/dashboard', [HubController::class, 'index'])->name('dashboard');
    Route::get('/projects', [HubController::class, 'index'])->name('projects.index');
    Route::get('/activity', [HubController::class, 'index'])->name('activity');
    Route::redirect('/approvals', '/activity?state=awaiting_approval')->name('approvals');
    Route::get('/environments/{environment}', [HubController::class, 'environment'])->name('environments.show');
    Route::post('/environments/{environment}/preflight', [DeploymentController::class, 'preflight'])->middleware(['mfa', 'throttle:10,1'])->name('environments.preflight');
    Route::get('/deployments/{deployment}', [DeploymentController::class, 'show'])->name('deployments.show');
    Route::get('/deployments/{deployment}/events', [DeploymentController::class, 'events'])->middleware('throttle:120,1')->name('deployments.events');
    Route::get('/security', [SecurityController::class, 'show'])->name('security');
    Route::get('/automation', [AutomationController::class, 'index'])->name('automation.index');
    Route::post('/automation', [AutomationController::class, 'store'])->middleware('throttle:5,1')->name('automation.store');
    Route::delete('/automation/{grant}', [AutomationController::class, 'destroy'])->name('automation.destroy');
    Route::post('/security/setup', [SecurityController::class, 'setup'])->middleware('throttle:5,1');
    Route::post('/security/enable', [SecurityController::class, 'enable'])->middleware('throttle:5,1');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::middleware('mfa')->group(function () {
        Route::post('/environments/{environment}/deployment-requests', [DeploymentController::class, 'store'])->middleware('throttle:10,1')->name('deployments.store');
        Route::post('/deployments/{deployment}/recover', [DeploymentController::class, 'recover'])->middleware('throttle:10,1')->name('deployments.recover');
        foreach (['confirm', 'cancel', 'reconcile'] as $action) {
            Route::post('/deployments/{deployment}/'.$action, [DeploymentController::class, $action])->name('deployments.'.$action);
        }
        Route::middleware('admin')->group(function () {
            Route::get('/servers', [ServerController::class, 'index'])->name('servers.index');
            Route::post('/servers', [ServerController::class, 'store']);
            Route::put('/servers/{server}', [ServerController::class, 'update']);
            foreach (['discover', 'verify', 'rotate'] as $action) {
                Route::post('/servers/{server}/'.$action, [ServerController::class, $action])->middleware('throttle:10,1');
            }
            Route::post('/servers/{server}/connection-test', [ServerController::class, 'test'])->middleware('throttle:10,1');
            Route::delete('/servers/{server}', [ServerController::class, 'destroy']);
            Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
            Route::post('/projects', [ProjectController::class, 'store']);
            Route::put('/projects/{project}', [ProjectController::class, 'updateProject']);
            Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);
            Route::get('/environments/{environment}/copy', [ProjectController::class, 'copy'])->name('projects.copy');
            Route::put('/environments/{environment}', [ProjectController::class, 'update']);
            Route::post('/environments/{environment}/permissions', [ProjectController::class, 'grant']);
            Route::get('/team', [TeamController::class, 'index'])->name('team.index');
            Route::post('/team', [TeamController::class, 'store']);
            Route::patch('/team/{user}', [TeamController::class, 'update']);
            Route::get('/audit-logs', [HubController::class, 'audit'])->name('audit');
        });
    });
});
require __DIR__.'/auth.php';
