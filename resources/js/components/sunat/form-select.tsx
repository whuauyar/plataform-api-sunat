import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';

export type SelectOption = { value: string; label: string };

/**
 * Wrapper delgado sobre el Select de shadcn/Radix con el estilo del formulario
 * (h-10 rounded-xl). Radix no admite SelectItem con value="", por eso el estado
 * vacío se representa con value=undefined (muestra el placeholder).
 */
export function FormSelect({
    value,
    onChange,
    options,
    placeholder = 'Seleccionar…',
    disabled = false,
    className,
}: {
    value: string;
    onChange: (value: string) => void;
    options: SelectOption[];
    placeholder?: string;
    disabled?: boolean;
    className?: string;
}) {
    return (
        <Select value={value || undefined} onValueChange={onChange} disabled={disabled}>
            <SelectTrigger className={cn('h-10 w-full rounded-xl bg-card dark:bg-background', className)}>
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                {options.map((o) => (
                    <SelectItem key={o.value} value={o.value}>
                        {o.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
