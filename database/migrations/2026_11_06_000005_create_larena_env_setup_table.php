<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The server's first setup (ENV task 2026-11-06): which steps the administrator finished or put off, whether the setup
 * was offered after the first sign-in and whether the reminder of what is left is hidden.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('larena_env_setup')) {
            return;
        }
        Schema::create('larena_env_setup', static function (Blueprint $table): void {
            $table->string('step', 32)->primary();
            $table->string('state', 16);
            $table->dateTime('marked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_env_setup');
    }
};
