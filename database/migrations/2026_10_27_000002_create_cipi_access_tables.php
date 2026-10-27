<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cipi_server_grants')) {
            // What a non-admin user may do on a whole server: view (read-only) or manage.
            Schema::create('cipi_server_grants', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->foreignId('server_id')->constrained('cipi_servers')->cascadeOnDelete();
                $table->string('level', 16);
                $table->timestamps();

                $table->unique(['user_id', 'server_id']);
            });
        }

        if (! Schema::hasTable('cipi_app_grants')) {
            // Abilities on one app (view, deploy, edit, env, logs, run, delete).
            Schema::create('cipi_app_grants', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->foreignId('server_id')->constrained('cipi_servers')->cascadeOnDelete();
                $table->string('app', 64);
                $table->json('abilities');
                $table->timestamps();

                $table->unique(['user_id', 'server_id', 'app']);
            });
        }

        if (! Schema::hasTable('cipi_invites')) {
            // A copyable link that creates one account with a role and its grants.
            Schema::create('cipi_invites', function (Blueprint $table) {
                $table->id();
                $table->string('token', 80)->unique();
                $table->string('label')->nullable();
                $table->string('role', 16)->default('user');
                $table->json('server_grants')->nullable();
                $table->json('app_grants')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('accepted_at')->nullable();
                $table->unsignedBigInteger('accepted_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cipi_invites');
        Schema::dropIfExists('cipi_app_grants');
        Schema::dropIfExists('cipi_server_grants');
    }
};
