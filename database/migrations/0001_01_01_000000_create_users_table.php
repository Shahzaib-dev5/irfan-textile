<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('name', 150);
            $table->string('email', 254);
            $table->unique('email', 'uq_users_email');
            $table->dateTime('email_verified_at', 6)->nullable();
            $table->string('password', 255);
            $table->rememberToken();
            $table->dateTime('last_login_at', 6)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->index(['is_active', 'name'], 'idx_users_is_active_name');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->string('email', 254)->primary();
            $table->string('token', 255);
            $table->dateTime('created_at', 6)->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->string('id', 255)->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->index('user_id', 'idx_sessions_user_id');
            $table->foreign('user_id', 'fk_sessions_users')->references('id')->on('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->unsignedInteger('last_activity');
            $table->index('last_activity', 'idx_sessions_last_activity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
