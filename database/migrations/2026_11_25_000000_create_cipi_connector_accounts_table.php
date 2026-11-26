<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings → Connectors (4.8.0): the accounts of Cloudflare, DigitalOcean,
 * Hetzner, AWS, Google Cloud, Azure, OVHcloud, Vultr, Akamai and Scaleway,
 * their credentials encrypted. Nothing to fill on update: no connector until
 * an admin adds an account.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cipi_connector_accounts')) {
            return;
        }

        Schema::create('cipi_connector_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('connector', 40)->index();
            $table->string('name', 80);
            $table->text('credentials');
            $table->string('status')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['connector', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cipi_connector_accounts');
    }
};
