<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The panel's letters (owner decision C335, step 2): the queue of ordinary letters the panel's scheduler sends each
 * minute, and the record of every letter for «История» → «Почта» — when, to whom, which, how it ended. A letter with a
 * sign-in link (a password reset, an invitation) is recorded only after it was sent, without its text: the link is
 * never kept. An ordinary letter keeps its text only while it waits; once sent or given up, the text is wiped.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('larena_env_mail_letters')) {
            return;
        }
        Schema::create('larena_env_mail_letters', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('kind', 32);
            $table->string('to_address', 254);
            $table->string('subject', 200);
            $table->text('body')->nullable();
            $table->string('state', 16);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_at')->nullable();
            $table->string('reason', 32)->nullable();
            $table->string('subject_ref', 191)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('finished_at')->nullable();
            $table->index(['state', 'next_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_env_mail_letters');
    }
};
