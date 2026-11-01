<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The warnings the panel's check saw last (Panel plan 4.5): a warning goes to people's notifications when it first
 * appears and again once a day while it lasts, never every 5 minutes; a warning that is gone is forgotten.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('larena_env_warnings')) {
            return;
        }
        Schema::create('larena_env_warnings', static function (Blueprint $table): void {
            $table->string('warning_key', 191)->primary();
            $table->string('kind', 64);
            $table->string('severity', 16);
            $table->string('domain', 253)->nullable();
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('notified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_env_warnings');
    }
};
