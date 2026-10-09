<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('enabled')->default(true);
            $t->text('two_factor_secret')->nullable();
            $t->timestamp('two_factor_confirmed_at')->nullable();
            $t->unsignedBigInteger('two_factor_last_counter')->nullable();
        });
        Schema::create('workspaces', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->timestamps();
        });
        Schema::create('workspace_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role')->default('viewer');
            $t->boolean('active')->default(true);
            $t->unique(['workspace_id', 'user_id']);
        });
        Schema::create('servers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained();
            $t->string('name');
            $t->string('host');
            $t->unsignedSmallInteger('port')->default(22);
            $t->string('username');
            $t->string('fingerprint')->nullable();
            $t->unsignedInteger('credential_version')->default(1);
            $t->string('status')->default('unverified');
            $t->timestamp('checked_at')->nullable();
            $t->string('error')->nullable();
            $t->timestamps();
        });
        Schema::create('server_credentials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('server_id')->unique()->constrained()->cascadeOnDelete();
            $t->longText('private_key');
            $t->text('passphrase')->nullable();
            $t->timestamps();
        });
        Schema::create('server_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('server_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unique(['server_id', 'user_id']);
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained();
            $t->string('name');
            $t->string('repository');
            $t->text('description')->nullable();
            $t->timestamps();
        });
        Schema::create('project_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unique(['project_id', 'user_id']);
        });
        Schema::create('environments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained();
            $t->foreignId('server_id')->constrained();
            $t->string('name');
            $t->string('path');
            $t->string('branch');
            $t->boolean('production')->default(false);
            $t->boolean('requires_approval')->default(false);
            $t->json('pipeline');
            $t->unsignedInteger('pipeline_version')->default(1);
            $t->string('health_url')->nullable();
            $t->string('status')->default('unknown');
            $t->json('last_preflight')->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
            $t->unique(['project_id', 'name']);
        });
        Schema::create('environment_permissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->boolean('can_view')->default(true);
            $t->boolean('can_deploy')->default(false);
            $t->boolean('can_approve')->default(false);
            $t->unique(['environment_id', 'user_id']);
        });
        Schema::create('deployments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('environment_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->uuid('idempotency_key');
            $t->string('state')->default('awaiting_confirmation');
            $t->char('starting_sha', 40);
            $t->char('target_sha', 40);
            $t->char('digest', 64);
            $t->json('candidate');
            $t->json('preflight');
            $t->timestamp('expires_at');
            $t->foreignId('active_environment_id')->nullable()->unique()->constrained('environments');
            $t->boolean('cancel_requested')->default(false);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('heartbeat_at')->nullable();
            $t->string('error')->nullable();
            $t->boolean('code_updated')->default(false);
            $t->timestamps();
            $t->unique(['environment_id', 'user_id', 'idempotency_key'], 'deployment_idempotency');
            $t->index(['environment_id', 'state', 'created_at']);
        });
        Schema::create('deployment_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $t->string('type');
            $t->string('state');
            $t->integer('exit_code')->nullable();
            $t->text('output')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
        Schema::create('deployment_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deployment_id')->unique()->constrained();
            $t->foreignId('user_id')->constrained();
            $t->char('digest', 64);
            $t->string('outcome');
            $t->string('reason')->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('action');
            $t->string('subject');
            $t->json('metadata');
            $t->timestamp('created_at');
            $t->index(['workspace_id', 'created_at']);
        });
        Schema::create('worker_heartbeats', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->timestamp('seen_at');
        });
    }

    public function down(): void
    {
        foreach (['worker_heartbeats', 'audit_logs', 'deployment_approvals', 'deployment_steps', 'deployments', 'environment_permissions', 'environments', 'project_user', 'projects', 'server_user', 'server_credentials', 'servers', 'workspace_user', 'workspaces'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['enabled', 'two_factor_secret', 'two_factor_confirmed_at', 'two_factor_last_counter']));
    }
};
