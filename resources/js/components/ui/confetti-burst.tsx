import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';

const COLORS = ['#F0990A', '#3599E6', '#00BA5D', '#8B5CF6', '#E63946'];

type Piece = {
    ox: number;
    oy: number;
    x: number;
    y: number;
    vx: number;
    vy: number;
    size: number;
    color: string;
    rot: number;
    vrot: number;
    life: number;
    t0: number; // ms hasta brotar (emisión escalonada)
    ttl: number; // vida útil desde que brota (ms)
    launched: boolean;
};

/**
 * "Erupción volcánica" suave y lenta, SIN dependencias. Las partículas brotan desde
 * la base (centro-abajo) hacia arriba en abanico y caen despacio con gravedad ligera.
 * La emisión es escalonada para que parezca un chorro sostenido, no un estallido seco.
 *
 * Se dibuja en un <canvas> a pantalla completa mediante un portal a document.body, con
 * pointer-events:none, así que nunca interfiere con modales, focus ni clics. Al terminar
 * se limpia y llama a onDone (útil para poder volver a dispararlo).
 */
export function ConfettiBurst({ show, onDone }: { show: boolean; onDone?: () => void }) {
    const canvasRef = useRef<HTMLCanvasElement | null>(null);
    const onDoneRef = useRef(onDone);
    onDoneRef.current = onDone;

    useEffect(() => {
        if (!show) return;
        const canvas = canvasRef.current;
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        let raf = 0;
        let running = true;
        const dpr = window.devicePixelRatio || 1;

        const resize = () => {
            canvas.width = window.innerWidth * dpr;
            canvas.height = window.innerHeight * dpr;
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        };
        resize();
        window.addEventListener('resize', resize);

        const W = () => window.innerWidth;
        const H = () => window.innerHeight;

        const GRAVITY = 0.075; // gravedad suave → caída lenta
        const EMIT = 1600; // ventana de emisión escalonada (ms)
        const cx = W() / 2;
        const vent = W() * 0.98; // brota a lo ancho de (casi) toda la pantalla

        // Brotan desde la base a lo ancho de toda la pantalla, hacia arriba en
        // abanico. Pocas piezas (unos cuantos) pero repartidas por todo el ancho.
        const pieces: Piece[] = Array.from({ length: 55 }, () => {
            const angle = (Math.random() - 0.5) * (Math.PI / 2.6);
            const speed = 7 + Math.random() * 6;
            return {
                ox: cx + (Math.random() - 0.5) * vent,
                oy: H() + 14,
                x: 0,
                y: 0,
                vx: Math.sin(angle) * speed,
                vy: -Math.cos(angle) * speed,
                size: 5 + Math.random() * 7,
                color: COLORS[Math.floor(Math.random() * COLORS.length)],
                rot: Math.random() * Math.PI,
                vrot: (Math.random() - 0.5) * 0.14,
                life: 1,
                t0: Math.random() * EMIT,
                ttl: 3600 + Math.random() * 1800,
                launched: false,
            };
        });

        const start = performance.now();
        const DURATION = EMIT + 5600;

        const tick = (now: number) => {
            if (!running) return;
            const elapsed = now - start;
            ctx.clearRect(0, 0, W(), H());

            for (const p of pieces) {
                if (elapsed < p.t0) continue; // aún no brota
                if (!p.launched) {
                    p.launched = true;
                    p.x = p.ox;
                    p.y = p.oy;
                }

                p.vy += GRAVITY;
                p.vx *= 0.996; // leve resistencia del aire
                p.x += p.vx;
                p.y += p.vy;
                p.rot += p.vrot;

                const localLife = elapsed - p.t0;
                if (localLife > p.ttl - 1000) p.life -= 0.018; // desvanecido lento
                if (p.life <= 0) continue;
                if (p.y > H() + 40) continue;

                ctx.save();
                ctx.globalAlpha = Math.max(0, p.life);
                ctx.translate(p.x, p.y);
                ctx.rotate(p.rot);
                ctx.fillStyle = p.color;
                ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.6);
                ctx.restore();
            }

            if (elapsed < DURATION) {
                raf = requestAnimationFrame(tick);
            } else {
                ctx.clearRect(0, 0, W(), H());
                onDoneRef.current?.();
            }
        };
        raf = requestAnimationFrame(tick);

        return () => {
            running = false;
            cancelAnimationFrame(raf);
            window.removeEventListener('resize', resize);
        };
    }, [show]);

    if (!show) return null;

    return createPortal(
        <canvas
            ref={canvasRef}
            style={{
                position: 'fixed',
                inset: 0,
                width: '100vw',
                height: '100vh',
                pointerEvents: 'none',
                zIndex: 9999,
            }}
        />,
        document.body,
    );
}
