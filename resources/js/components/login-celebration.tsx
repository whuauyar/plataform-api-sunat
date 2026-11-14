import { useEffect, useState } from 'react';
import { ConfettiBurst } from '@/components/ui/confetti-burst';

/**
 * Dispara el confeti (erupción suave) una sola vez tras iniciar sesión. El formulario
 * de login marca una bandera en sessionStorage al enviarse con éxito; al aterrizar en
 * el panel se lee, se limpia y se celebra. Envuelto en try/catch por si el navegador
 * bloquea el storage (modo privado) — en ese caso simplemente no celebra.
 */
export function LoginCelebration() {
    const [show, setShow] = useState(false);

    useEffect(() => {
        try {
            if (sessionStorage.getItem('celebrate_login')) {
                sessionStorage.removeItem('celebrate_login');
                setShow(true);
            }
        } catch {
            // storage no disponible → sin celebración, sin romper nada
        }
    }, []);

    return <ConfettiBurst show={show} onDone={() => setShow(false)} />;
}
