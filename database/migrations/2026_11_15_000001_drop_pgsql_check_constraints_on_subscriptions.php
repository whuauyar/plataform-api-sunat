<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Completa en PostgreSQL la conversión ENUM → VARCHAR de 2026_04_19_180000.
 *
 * En PostgreSQL Laravel crea un enum como `varchar + CHECK ({tabla}_{columna}_check)`
 * y `->change()` solo cambia el tipo y el default: el CHECK original sigue vivo.
 * Por eso `subscriptions.status = 'pending'` (usado al cambiar de plan) fallaba con
 * "violates check constraint subscriptions_status_check".
 *
 * En MySQL/SQLite no hace nada: allí la conversión de 2026_04_19 ya es completa.
 * Los valores válidos siguen controlados por la aplicación (Model + FormRequest),
 * igual que en MySQL tras la conversión a VARCHAR.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> tabla => columnas cuyo CHECK de enum se elimina */
    private array $columnas = [
        'subscriptions' => ['status', 'billing_cycle'],
        'subscription_payments' => ['status'],
    ];

    /** @var array<string, array<string, list<string>>> valores originales del enum, para el rollback */
    private array $valoresOriginales = [
        'subscriptions' => [
            'status' => ['trialing', 'active', 'past_due', 'cancelled', 'expired'],
            'billing_cycle' => ['monthly', 'yearly'],
        ],
        'subscription_payments' => [
            'status' => ['pending', 'completed', 'failed', 'refunded'],
        ],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->columnas as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                DB::statement("ALTER TABLE IF EXISTS {$tabla} DROP CONSTRAINT IF EXISTS {$tabla}_{$columna}_check");
            }
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Igual que el down() de 2026_04_19: 'pending' no existía en el enum original.
        DB::table('subscriptions')->where('status', 'pending')->update(['status' => 'trialing']);

        foreach ($this->valoresOriginales as $tabla => $columnas) {
            foreach ($columnas as $columna => $valores) {
                $lista = implode(', ', array_map(fn (string $v) => "'{$v}'", $valores));
                DB::statement("ALTER TABLE IF EXISTS {$tabla} DROP CONSTRAINT IF EXISTS {$tabla}_{$columna}_check");
                DB::statement("ALTER TABLE IF EXISTS {$tabla} ADD CONSTRAINT {$tabla}_{$columna}_check CHECK ({$columna} IN ({$lista}))");
            }
        }
    }
};
