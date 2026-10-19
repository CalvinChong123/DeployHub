<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_grant_targets', function (Blueprint $table) {
            $table->foreignId('automation_grant_id');
            $table->foreignId('environment_id')->constrained()->restrictOnDelete();
            $table->string('target_fingerprint', 64);
            $table->primary(['automation_grant_id', 'environment_id']);
        });

        DB::table('automation_grants')->select('id', 'environment_id', 'target_fingerprint')->orderBy('id')
            ->chunkById(500, function ($grants) {
                DB::table('automation_grant_targets')->insert($grants->map(fn ($grant) => [
                    'automation_grant_id' => $grant->id, 'environment_id' => $grant->environment_id,
                    'target_fingerprint' => $grant->target_fingerprint,
                ])->all());
            });

        Schema::table('automation_grants', function (Blueprint $table) {
            $table->dropForeign(['environment_id']);
            $table->dropColumn(['environment_id', 'target_fingerprint']);
        });

        Schema::table('automation_grant_targets', function (Blueprint $table) {
            $table->foreign('automation_grant_id')->references('id')->on('automation_grants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Multi-target authorization is a forward-only migration. Restore a database backup with matching code instead of collapsing target scopes.');
    }
};
