<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The panel's own mail (owner decision C335): one row of settings, the password encrypted with the application's key
 * (never in `.env`), and where mail stands — waiting for a test letter, on, off, or off because the key cannot open the
 * password any more.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('larena_env_mail')) {
            return;
        }
        Schema::create('larena_env_mail', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('service', 16);
            $table->string('host', 253);
            $table->unsignedInteger('port');
            $table->string('encryption', 8);
            $table->string('login', 254);
            $table->text('password_encrypted')->nullable();
            $table->string('from_address', 254);
            $table->string('from_name', 120);
            $table->string('levels', 64)->default('urgent');
            $table->string('state', 16);
            $table->timestamp('checked_at')->nullable();
            $table->string('updated_by', 191);
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_env_mail');
    }
};
