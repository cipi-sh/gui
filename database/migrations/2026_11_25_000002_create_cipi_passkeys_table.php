<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passkeys (4.8.0): sign in with Face ID, Touch ID, Windows Hello, a phone or
 * a security key, next to the password and its two-factor code. A passkey's
 * public key only: the private one never leaves the person's device.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cipi_passkeys')) {
            return;
        }

        Schema::create('cipi_passkeys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name', 80);
            // The id can be up to 1023 bytes: looked up by its hash, which any database indexes.
            $table->char('credential_hash', 64)->unique();
            $table->text('credential_id');
            $table->text('public_key');
            $table->smallInteger('alg')->default(-7);
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->string('aaguid', 32)->nullable();
            $table->json('transports')->nullable();
            $table->boolean('backed_up')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cipi_passkeys');
    }
};
