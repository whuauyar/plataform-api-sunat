import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Loader2, ShieldCheck } from '@/lib/icons';
import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import type { BreadcrumbItem } from '@/types';

type Props = {
    licencia: {
        machine_id: string;
        dominio: string;
        servidor: string;
        configurada: boolean;
        clave_preview: string;
        estado: { permitida: boolean; codigo: string; mensaje: string; gracia: boolean };
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Administración', href: '#' },
    { title: 'Licencia', href: '/admin/licencia' },
];

export default function LicenciaIndex({ licencia }: Props) {
    const [clave, setClave] = useState('');
    const [secreto, setSecreto] = useState('');
    const [activando, setActivando] = useState(false);

    const { estado } = licencia;
    const badge = estado.permitida
        ? estado.gracia
            ? { variant: 'secondary' as const, texto: 'Gracia offline' }
            : { variant: 'default' as const, texto: 'Activa' }
        : { variant: 'destructive' as const, texto: 'No activa' };

    const activar = () => {
        setActivando(true);
        router.post(
            '/admin/licencia/activar',
            { license_key: clave, license_secret: secreto },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setClave('');
                    setSecreto('');
                },
                onFinish: () => setActivando(false),
            },
        );
    };

    const puedeActivar = clave.trim() !== '' && secreto.trim() !== '' && !activando;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Licencia" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex items-center gap-3">
                    <div className="text-primary flex size-10 items-center justify-center rounded-lg">
                        <ShieldCheck className="size-5" />
                    </div>
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">Licencia Pro</h1>
                        <p className="text-muted-foreground text-sm">
                            Activa la emisión en producción pegando tus credenciales de licencia.
                        </p>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Estado actual */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle>Estado</CardTitle>
                                <Badge variant={badge.variant}>{badge.texto}</Badge>
                            </div>
                            <CardDescription>{estado.mensaje}</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <Dato etiqueta="Instalación (machine id)" valor={licencia.machine_id} mono />
                            <Dato etiqueta="Dominio" valor={licencia.dominio} />
                            <Dato etiqueta="Servidor de licencias" valor={licencia.servidor} />
                            <Dato
                                etiqueta="Clave configurada"
                                valor={licencia.configurada ? licencia.clave_preview : 'No configurada'}
                                mono={licencia.configurada}
                            />
                        </CardContent>
                    </Card>

                    {/* Activar */}
                    <Card>
                        <CardHeader>
                            <CardTitle>{licencia.configurada ? 'Reemplazar credenciales' : 'Activar licencia'}</CardTitle>
                            <CardDescription>
                                Pega la Clave y el Secreto que te entregó el proveedor y pulsa Activar. Se guardan de forma
                                segura; el secreto queda cifrado.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="license_key">Clave de licencia</Label>
                                <PasswordInput
                                    id="license_key"
                                    value={clave}
                                    onChange={(e) => setClave(e.target.value)}
                                    placeholder="LICENSE_KEY"
                                    autoComplete="off"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="license_secret">Secreto de licencia</Label>
                                <PasswordInput
                                    id="license_secret"
                                    value={secreto}
                                    onChange={(e) => setSecreto(e.target.value)}
                                    placeholder="LICENSE_SECRET"
                                    autoComplete="off"
                                />
                            </div>
                            <Button type="button" onClick={activar} disabled={!puedeActivar}>
                                {activando && <Loader2 className="size-4 animate-spin" />}
                                {activando ? 'Activando…' : 'Activar licencia'}
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}

function Dato({ etiqueta, valor, mono = false }: { etiqueta: string; valor: string; mono?: boolean }) {
    return (
        <div className="flex flex-col gap-0.5">
            <span className="text-muted-foreground text-xs">{etiqueta}</span>
            <span className={mono ? 'font-mono text-xs break-all' : 'break-all'}>{valor || '—'}</span>
        </div>
    );
}
