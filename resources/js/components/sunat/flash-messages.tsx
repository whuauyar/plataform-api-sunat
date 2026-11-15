import { useEffect, useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { CheckCircle, XCircle, Info, AlertTriangle, X } from '@/lib/icons';
import type { FlashProps } from '@/types';

type Message = { type: 'success' | 'error' | 'info' | 'warning'; text: string };

const ICONS = {
    success: CheckCircle,
    error:   XCircle,
    info:    Info,
    warning: AlertTriangle,
};

const COLORS = {
    success: 'bg-green-50 border-green-200 text-green-800 dark:bg-green-950/50 dark:border-green-800 dark:text-green-300',
    error:   'bg-red-50 border-red-200 text-red-800 dark:bg-red-950/50 dark:border-red-800 dark:text-red-300',
    info:    'bg-blue-50 border-blue-200 text-blue-800 dark:bg-blue-950/50 dark:border-blue-800 dark:text-blue-300',
    warning: 'bg-yellow-50 border-yellow-200 text-yellow-800 dark:bg-yellow-950/50 dark:border-yellow-800 dark:text-yellow-300',
};

export function FlashMessages() {
    const { flash } = usePage<{ flash: FlashProps }>().props;

    // Mismo comportamiento que la versión original, sin setState dentro del effect:
    // - Un flash CON mensajes los muestra y programa su cierre a los 6 s.
    // - Un flash SIN mensajes no borra lo que está en pantalla (se conservan los
    //   últimos mensajes mostrados), igual que antes.
    // - Si llega otro flash antes de los 6 s, el cierre programado se cancela.
    const nuevos = useMemo<Message[]>(() => {
        const msgs: Message[] = [];
        if (flash?.success) msgs.push({ type: 'success', text: flash.success });
        if (flash?.error)   msgs.push({ type: 'error',   text: flash.error });
        if (flash?.info)    msgs.push({ type: 'info',     text: flash.info });
        if (flash?.warning) msgs.push({ type: 'warning',  text: flash.warning });
        return msgs;
    }, [flash]);

    // Ajuste de estado cuando cambian las props (patrón recomendado por React):
    // solo un flash con mensajes reemplaza lo mostrado.
    const [flashPrevio, setFlashPrevio] = useState<FlashProps | undefined>(undefined);
    const [messages, setMessages] = useState<Message[]>([]);
    if (flash !== flashPrevio) {
        setFlashPrevio(flash);
        if (nuevos.length) setMessages(nuevos);
    }

    useEffect(() => {
        if (!nuevos.length) return;
        const t = setTimeout(() => setMessages([]), 6000);
        return () => clearTimeout(t);
    }, [flash, nuevos]);

    if (!messages.length) return null;

    return (
        <div className="fixed right-4 top-4 z-50 flex flex-col gap-2">
            {messages.map((msg, i) => {
                const Icon = ICONS[msg.type];
                return (
                    <div key={i} className={`flex items-start gap-3 rounded-lg border px-4 py-3 shadow-md max-w-sm ${COLORS[msg.type]}`}>
                        <Icon className="mt-0.5 size-4 shrink-0" />
                        <p className="text-sm font-medium">{msg.text}</p>
                        <button
                            onClick={() => setMessages(m => m.filter((_, j) => j !== i))}
                            className="ml-auto shrink-0 opacity-60 hover:opacity-100"
                        >
                            <X className="size-3.5" />
                        </button>
                    </div>
                );
            })}
        </div>
    );
}
