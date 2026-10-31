<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person of the panel at its user level and the owner of the server whose space they work in (Panel plan, stage 2).
 * The owner is simai-env's: its sites run as the owner's account, and the gateway narrows every answer to them.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('larena_env_person_owners')) {
            return;
        }
        Schema::create('larena_env_person_owners', static function (Blueprint $table): void {
            $table->string('subject_ref', 191)->primary();
            $table->string('owner', 32);
            $table->string('assigned_by', 191);
            $table->timestamp('assigned_at');
            $table->index('owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_env_person_owners');
    }
};
