<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soporte de Idempotency-Key a nivel API: evita que un reintento del cliente
 * (tras un timeout de red) emita un comprobante DUPLICADO. Se guarda la respuesta
 * de la primera petición y se devuelve igual ante la misma key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('idempotency_key');
            $table->string('request_hash', 64);        // sha256(method|path|body)
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index('created_at'); // para purga de llaves viejas
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
