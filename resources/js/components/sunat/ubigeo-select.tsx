import { useEffect, useState } from 'react';
import { FormSelect } from '@/components/sunat/form-select';
import { Label } from '@/components/ui/label';

type Opt = { id: string; nombre: string };

async function fetchJson(url: string): Promise<Opt[]> {
    try {
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!res.ok) return [];
        const j = await res.json();
        return (j?.datos ?? []) as Opt[];
    } catch {
        return [];
    }
}

/**
 * Selects dependientes región → provincia → distrito. Al elegir distrito llama
 * onChange con el ubigeo de 6 dígitos (el que va a partida_ubigeo/llegada_ubigeo).
 * Consume las rutas del panel: /sunat/ubigeo/{regiones|provincias|distritos}.
 */
export function UbigeoSelect({
    label,
    onChange,
    initial = '',
}: {
    label: string;
    onChange: (ubigeo: string) => void;
    /** Ubigeo de 6 dígitos para pre-seleccionar los 3 selects (modo edición). */
    initial?: string;
}) {
    const [regiones, setRegiones] = useState<Opt[]>([]);
    const [provincias, setProvincias] = useState<Opt[]>([]);
    const [distritos, setDistritos] = useState<Opt[]>([]);
    const [region, setRegion] = useState('');
    const [provincia, setProvincia] = useState('');
    const [distrito, setDistrito] = useState('');

    useEffect(() => {
        fetchJson('/sunat/ubigeo/regiones').then(setRegiones);
    }, []);

    // Pre-selección desde un ubigeo de 6 dígitos: región=2, provincia=4, distrito=6.
    useEffect(() => {
        if (! /^\d{6}$/.test(initial)) return;
        const reg = initial.slice(0, 2);
        const prov = initial.slice(0, 4);
        setRegion(reg);
        setProvincia(prov);
        setDistrito(initial);
        fetchJson(`/sunat/ubigeo/provincias?region_id=${reg}`).then(setProvincias);
        fetchJson(`/sunat/ubigeo/distritos?provincia_id=${prov}`).then(setDistritos);
        onChange(initial);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [initial]);

    const onRegion = (r: string) => {
        setRegion(r);
        setProvincia('');
        setDistrito('');
        setProvincias([]);
        setDistritos([]);
        onChange('');
        if (r) fetchJson(`/sunat/ubigeo/provincias?region_id=${r}`).then(setProvincias);
    };

    const onProvincia = (p: string) => {
        setProvincia(p);
        setDistrito('');
        setDistritos([]);
        onChange('');
        if (p) fetchJson(`/sunat/ubigeo/distritos?provincia_id=${p}`).then(setDistritos);
    };

    const onDistrito = (d: string) => {
        setDistrito(d);
        onChange(d);
    };

    return (
        <div className="flex flex-col gap-1.5">
            <Label className="text-xs font-medium text-muted-foreground">{label}</Label>
            <div className="grid gap-2 sm:grid-cols-3">
                <FormSelect
                    value={region}
                    onChange={onRegion}
                    placeholder="Región…"
                    options={regiones.map((r) => ({ value: r.id, label: r.nombre }))}
                />
                <FormSelect
                    value={provincia}
                    onChange={onProvincia}
                    disabled={!region}
                    placeholder="Provincia…"
                    options={provincias.map((p) => ({ value: p.id, label: p.nombre }))}
                />
                <FormSelect
                    value={distrito}
                    onChange={onDistrito}
                    disabled={!provincia}
                    placeholder="Distrito…"
                    options={distritos.map((d) => ({ value: d.id, label: d.nombre }))}
                />
            </div>
            {distrito && <span className="text-[11px] text-muted-foreground">Ubigeo: {distrito}</span>}
        </div>
    );
}
