<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora (append-only) de acciones sensibles: quién anuló un comprobante,
 * regeneró credenciales, cambió configuración o certificado, etc. (S5 auditoría).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();

            // usuario (panel) | api (credenciales de empresa) | sistema (jobs/cron)
            $table->string('actor_tipo', 20)->default('sistema');
            $table->string('actor_etiqueta')->nullable();

            // Clave de la acción, p. ej. credenciales.regeneradas, comprobante.anulado
            $table->string('accion', 80)->index();

            // Sujeto afectado (opcional): modelo + id
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();

            $table->string('descripcion')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('metadata')->nullable();

            // Append-only: solo created_at.
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
