<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The panel's DNS (Panel plan, stage 7): the one connection to a DNS provider, its token kept encrypted with the
 * application's key and never shown again, and the changes made through the panel, each with the record as it was,
 * so the last one can be put back.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('larena_env_dns_connection')) {
            Schema::create('larena_env_dns_connection', static function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->string('provider', 32);
                $table->text('token_encrypted');
                $table->string('token_tail', 8);
                $table->string('token_status', 32);
                $table->string('token_expires_on', 40)->nullable();
                $table->string('server_ipv4', 15)->nullable();
                $table->string('server_ipv6', 45)->nullable();
                $table->string('connected_by', 254);
                $table->dateTime('verified_at');
            });
        }
        if (!Schema::hasTable('larena_env_dns_changes')) {
            Schema::create('larena_env_dns_changes', static function (Blueprint $table): void {
                $table->id();
                $table->string('batch', 32)->index();
                $table->string('zone_id', 32)->index();
                $table->string('zone_name', 253);
                $table->string('action', 16);
                $table->text('before')->nullable();
                $table->text('after')->nullable();
                $table->string('actor', 254);
                $table->string('undo_of', 32)->nullable();
                $table->dateTime('created_at');
                $table->dateTime('undone_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_env_dns_changes');
        Schema::dropIfExists('larena_env_dns_connection');
    }
};
