<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people of the panel's user level who may open a site's files with passwords (owner decision 2026-11-18,
 * q-4997ae4e93): off unless switched on for the person; the whole server has the right without a row.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('larena_env_secret_file_readers')) {
            return;
        }
        Schema::create('larena_env_secret_file_readers', static function (Blueprint $table): void {
            $table->string('subject_ref', 191)->primary();
            $table->string('granted_by', 191);
            $table->timestamp('granted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_env_secret_file_readers');
    }
};
