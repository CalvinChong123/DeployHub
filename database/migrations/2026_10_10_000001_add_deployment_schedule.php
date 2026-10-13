<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->timestamp('scheduled_for')->nullable();
            $table->string('schedule_timezone', 64)->nullable();
            $table->index(['state', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropIndex(['state', 'scheduled_for']);
            $table->dropColumn(['scheduled_for', 'schedule_timezone']);
        });
    }
};
