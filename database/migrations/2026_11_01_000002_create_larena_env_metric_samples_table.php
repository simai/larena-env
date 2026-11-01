<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The server's load as the panel measured it every 5 minutes (Panel plan 4.6), kept 30 days. */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('larena_env_metric_samples')) {
            return;
        }
        Schema::create('larena_env_metric_samples', static function (Blueprint $table): void {
            $table->id();
            $table->dateTime('sampled_at')->index();
            $table->unsignedInteger('cores')->nullable();
            $table->double('load1')->nullable();
            $table->unsignedBigInteger('memory_total')->nullable();
            $table->unsignedBigInteger('memory_used')->nullable();
            $table->unsignedBigInteger('swap_total')->nullable();
            $table->unsignedBigInteger('swap_used')->nullable();
            $table->text('disks');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_env_metric_samples');
    }
};
