<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Puebla ubigeo_regiones / ubigeo_provincias / ubigeo_distritos desde
 * public/data_ubi.txt (pipe-separated: id|nombre|info_busqueda|provincia_id|region_id,
 * una fila por distrito). Región = 2 díg, provincia = 4 díg, distrito = 6 díg.
 * Idempotente: usa upsert, así php artisan db:seed puede correr varias veces.
 */
class UbigeoSeeder extends Seeder
{
    public function run(): void
    {
        $path = public_path('data_ubi.txt');
        if (! is_file($path)) {
            $this->command?->warn("UbigeoSeeder: no se encontró {$path}; se omite.");

            return;
        }

        $fh = fopen($path, 'r');
        if (! $fh) {
            $this->command?->warn('UbigeoSeeder: no se pudo abrir data_ubi.txt; se omite.');

            return;
        }

        $regiones = [];   // code2 => nombre
        $provincias = []; // code4 => [nombre, region_id]
        $distritos = [];  // code6 => [nombre, provincia_id, region_id]

        fgets($fh); // saltar cabecera
        while (($line = fgets($fh)) !== false) {
            $line = trim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            $p = explode('|', $line);
            if (count($p) < 3) {
                continue;
            }
            $id = trim($p[0]);
            $nombre = trim($p[1]);
            $info = $p[2] ?? '';
            if (strlen($id) < 6) {
                continue;
            }

            $rcode = substr($id, 0, 2);
            $pcode = substr($id, 0, 4);
            $parts = array_map('trim', explode('/', $info));
            $regName = $parts[2] ?? '';
            $provName = $parts[1] ?? '';

            $regiones[$rcode] = $regName;
            $provincias[$pcode] = [$provName, $rcode];
            $distritos[$id] = [$nombre, $pcode, $rcode];
        }
        fclose($fh);

        DB::transaction(function () use ($regiones, $provincias, $distritos) {
            $rRows = [];
            foreach ($regiones as $id => $nombre) {
                $rRows[] = ['id' => $id, 'nombre' => $nombre];
            }
            foreach (array_chunk($rRows, 500) as $chunk) {
                DB::table('ubigeo_regiones')->upsert($chunk, ['id'], ['nombre']);
            }

            $pRows = [];
            foreach ($provincias as $id => [$nombre, $regId]) {
                $pRows[] = ['id' => $id, 'nombre' => $nombre, 'region_id' => $regId];
            }
            foreach (array_chunk($pRows, 500) as $chunk) {
                DB::table('ubigeo_provincias')->upsert($chunk, ['id'], ['nombre', 'region_id']);
            }

            $dRows = [];
            foreach ($distritos as $id => [$nombre, $provId, $regId]) {
                $dRows[] = ['id' => $id, 'nombre' => $nombre, 'provincia_id' => $provId, 'region_id' => $regId];
            }
            foreach (array_chunk($dRows, 500) as $chunk) {
                DB::table('ubigeo_distritos')->upsert($chunk, ['id'], ['nombre', 'provincia_id', 'region_id']);
            }
        });

        $this->command?->info(
            'UbigeoSeeder: '.count($regiones).' regiones, '.count($provincias).' provincias, '.count($distritos).' distritos.'
        );
    }
}
