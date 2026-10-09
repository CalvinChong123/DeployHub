<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', fn (Blueprint $t) => $t->uuid('execution_token')->nullable());
    }

    public function down(): void
    {
        Schema::table('deployments', fn (Blueprint $t) => $t->dropColumn('execution_token'));
    }
};
