<?php

use App\Services\Deployment\DeploymentWorkflow;
use App\Services\ProjectRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('deployments')->whereNotNull('active_environment_id')->exists()) {
            throw new RuntimeException('Finish or cancel active deployments and reconcile unknown targets before migrating project configuration.');
        }
        Schema::table('projects', fn (Blueprint $table) => $table->softDeletes());
        Schema::table('environments', function (Blueprint $table) {
            $table->softDeletes();
            $table->string('registered_path')->nullable();
        });
        DB::transaction(function () {
            ProjectRegistry::separateTargets();
            foreach (DB::table('environments')->orderBy('id')->get() as $env) {
                $hooks = DeploymentWorkflow::defaults(json_decode($env->pipeline, true) ?? [], json_decode($env->hooks ?? '[]', true) ?? []);
                DB::table('environments')->where('id', $env->id)->update(['hooks' => json_encode($hooks, JSON_THROW_ON_ERROR), 'pipeline' => '[]',
                    'registered_path' => $env->path, 'pipeline_version' => $env->pipeline_version + 1, 'last_preflight' => null, 'checked_at' => null, 'status' => 'unknown']);
            }
        });
        Schema::table('environments', function (Blueprint $table) {
            $table->unique(['server_id', 'registered_path']);
            $table->unique('project_id');
            $table->dropUnique('unique_remote_directory');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Project separation preserves history and must not be reversed by merging or deleting records.');
    }
};
