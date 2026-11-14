<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cifra el api_secret de los tenants en reposo. El valor cifrado (~312 chars) no
 * cabe en el VARCHAR(64) original, así que primero se ensancha la columna a TEXT
 * y luego se cifran los valores existentes (idempotente: salta los ya cifrados).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function ($table) {
            $table->text('api_secret')->change();
        });

        DB::table('tenants')->select('id', 'api_secret')->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $r) {
                    if (empty($r->api_secret)) {
                        continue;
                    }
                    // ¿Ya cifrado? (decryptString no lanza) → saltar.
                    try {
                        Crypt::decryptString($r->api_secret);
                        continue;
                    } catch (DecryptException) {
                        // texto plano → cifrar
                    }
                    DB::table('tenants')->where('id', $r->id)
                        ->update(['api_secret' => Crypt::encryptString($r->api_secret)]);
                }
            });
    }

    public function down(): void
    {
        DB::table('tenants')->select('id', 'api_secret')->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $r) {
                    if (empty($r->api_secret)) {
                        continue;
                    }
                    try {
                        $plano = Crypt::decryptString($r->api_secret);
                    } catch (DecryptException) {
                        continue; // ya está en claro
                    }
                    DB::table('tenants')->where('id', $r->id)
                        ->update(['api_secret' => $plano]);
                }
            });

        Schema::table('tenants', function ($table) {
            $table->string('api_secret', 64)->change();
        });
    }
};
