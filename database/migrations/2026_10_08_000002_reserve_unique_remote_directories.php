<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', fn (Blueprint $t) => $t->unique(['server_id', 'path'], 'unique_remote_directory'));
    }

    public function down(): void
    {
        Schema::table('environments', fn (Blueprint $t) => $t->dropUnique('unique_remote_directory'));
    }
};
