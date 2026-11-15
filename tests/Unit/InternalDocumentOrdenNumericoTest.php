<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\InternalDocument;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El correlativo de cotizaciones y notas de venta se ordena con un CAST cuya
 * sintaxis depende del motor. `AS UNSIGNED` solo existe en MySQL: en PostgreSQL
 * hacía fallar con 500 la creación de cotizaciones y notas de venta.
 *
 * Este test fija la expresión de cada motor; en particular, que MySQL conserve
 * exactamente la expresión original del proyecto base.
 */
class InternalDocumentOrdenNumericoTest extends TestCase
{
    private const POSICION = 12;

    /** @return array<string, array{0: string, 1: string}> */
    public static function motores(): array
    {
        return [
            'mysql conserva la expresion original' => ['mysql', 'CAST(SUBSTRING(numero, 12) AS UNSIGNED)'],
            'mariadb igual que mysql' => ['mariadb', 'CAST(SUBSTRING(numero, 12) AS UNSIGNED)'],
            'postgresql usa BIGINT y FROM' => ['pgsql', 'CAST(SUBSTRING(numero FROM 12) AS BIGINT)'],
            'sqlite usa SUBSTR e INTEGER' => ['sqlite', 'CAST(SUBSTR(numero, 12) AS INTEGER)'],
            'sql server exige longitud en SUBSTRING' => ['sqlsrv', 'CAST(SUBSTRING(numero, 12, 30) AS BIGINT)'],
            'motor desconocido cae en la expresion original' => ['otro', 'CAST(SUBSTRING(numero, 12) AS UNSIGNED)'],
        ];
    }

    #[DataProvider('motores')]
    public function test_genera_la_expresion_de_cada_motor(string $motor, string $esperada): void
    {
        $this->assertSame($esperada, InternalDocument::expresionOrdenNumerico($motor, self::POSICION));
    }

    public function test_postgresql_no_usa_unsigned(): void
    {
        $this->assertStringNotContainsString('UNSIGNED', InternalDocument::expresionOrdenNumerico('pgsql', self::POSICION));
    }

    public function test_rechaza_una_posicion_menor_a_uno(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('La posición inicial debe ser mayor o igual a 1.');

        InternalDocument::expresionOrdenNumerico('pgsql', 0);
    }
}
