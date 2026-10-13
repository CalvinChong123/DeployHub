<?php

use App\Http\Middleware\AuthenticateAutomation;
use App\Mcp\Servers\DeployHubServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::middleware([AuthenticateAutomation::class, 'throttle:60,1'])->group(function () {
    Mcp::web('/mcp/deployhub', DeployHubServer::class)->name('mcp.deployhub');
});
