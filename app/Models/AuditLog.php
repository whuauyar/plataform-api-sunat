<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Bitácora de acciones sensibles (append-only).
 *
 * Uso típico desde un controlador/servicio:
 *   AuditLog::registrar('credenciales.regeneradas', [
 *       'tenant_id'   => $tenant->id,
 *       'subject'     => $tenant,
 *       'descripcion' => 'Regeneró api_key y api_secret',
 *   ]);
 *
 * Resuelve el actor (usuario del panel o empresa por API), la IP y el user-agent
 * automáticamente desde la petición actual. Nunca lanza: una falla de auditoría
 * no debe tumbar la acción que la originó.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Registra una acción sensible.
     *
     * @param  array{tenant_id?:int|null,subject?:Model|null,subject_type?:string|null,subject_id?:int|string|null,descripcion?:string|null,metadata?:array<string,mixed>|null}  $opts
     */
    public static function registrar(string $accion, array $opts = []): void
    {
        try {
            $request = request();
            $user = Auth::guard('web')->user() ?? $request?->user();
            $tenant = $request?->get('tenant'); // lo inyecta ResolveTenant (API)

            // En el panel admin el tenant llega por route-model-binding, no como input.
            $routeTenant = $request?->route('tenant');
            $routeTenant = $routeTenant instanceof Tenant ? $routeTenant : null;

            [$actorTipo, $actorEtiqueta] = self::resolverActor($user, $tenant);

            $subject = $opts['subject'] ?? null;

            self::create([
                'tenant_id' => $opts['tenant_id']
                    ?? ($tenant->id ?? null)
                    ?? $routeTenant?->id
                    ?? ($subject instanceof Tenant ? $subject->getKey() : null),
                'user_id' => $user?->getKey(),
                'actor_tipo' => $actorTipo,
                'actor_etiqueta' => $actorEtiqueta,
                'accion' => $accion,
                'subject_type' => $opts['subject_type'] ?? ($subject ? $subject::class : null),
                'subject_id' => $opts['subject_id'] ?? ($subject?->getKey()),
                'descripcion' => $opts['descripcion'] ?? null,
                'ip' => $request?->ip(),
                'user_agent' => substr((string) $request?->userAgent(), 0, 512),
                'metadata' => $opts['metadata'] ?? null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e); // no re-lanza: auditar nunca rompe la acción
        }
    }

    /**
     * @return array{0:string,1:?string}
     */
    private static function resolverActor(?object $user, ?object $tenant): array
    {
        if ($user) {
            $etiqueta = trim(($user->name ?? 'Usuario').' <'.($user->email ?? '').'>');

            return ['usuario', $etiqueta];
        }

        if ($tenant) {
            return ['api', 'API empresa '.($tenant->ruc ?? $tenant->id ?? '?')];
        }

        return ['sistema', null];
    }
}
