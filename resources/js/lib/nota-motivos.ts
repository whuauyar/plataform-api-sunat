import type { ItemRow } from '@/components/sunat/items-editor';

/**
 * Comportamiento del formulario de nota (crédito/débito) según el motivo SUNAT.
 * Cada motivo habilita distintas secciones y decide qué campos de los ítems se
 * pueden editar, para que la emisión sea coherente con lo que SUNAT espera.
 *
 * itemsMode:
 *   - 'locked'          → ítems fijos (copia total del comprobante), sin editar/quitar/agregar.
 *   - 'edit_description'→ solo la descripción es editable (corrección de texto).
 *   - 'edit_quantity'   → solo la cantidad es editable + se pueden quitar ítems (devolución parcial).
 *   - 'edit_price'      → solo el precio unitario es editable (ajuste/aumento de valor).
 *   - 'edit_discount'   → solo el descuento por ítem es editable.
 *   - 'free'            → edición completa y se pueden agregar ítems (conceptos libres).
 */
export type ItemsMode =
    | 'locked'
    | 'edit_description'
    | 'edit_quantity'
    | 'edit_price'
    | 'edit_discount'
    | 'free';

export type MotivoConfig = {
    itemsMode: ItemsMode;
    allowAdd: boolean;
    allowRemove: boolean;
    showDescuentoGlobal: boolean;
    itemsTitulo: string;
    aviso?: string;
};

/** Campos del ítem que quedan bloqueados en el modal según el modo. */
export function lockedFieldsFor(mode: ItemsMode): (keyof ItemRow)[] {
    const todos: (keyof ItemRow)[] = [
        'tipo_item', 'codigo', 'cod_producto_sunat', 'descripcion',
        'unidad', 'cantidad', 'precio_unitario', 'descuento_pct', 'tip_afe_igv',
    ];
    switch (mode) {
        case 'edit_description':
            return todos.filter((f) => f !== 'descripcion');
        case 'edit_quantity':
            return todos.filter((f) => f !== 'cantidad');
        case 'edit_price':
            return todos.filter((f) => f !== 'precio_unitario');
        case 'edit_discount':
            return todos.filter((f) => f !== 'descuento_pct');
        case 'locked':
            return todos;
        case 'free':
        default:
            return [];
    }
}

const NC_DEFAULT: MotivoConfig = {
    itemsMode: 'free',
    allowAdd: true,
    allowRemove: true,
    showDescuentoGlobal: false,
    itemsTitulo: 'Ítems a devolver / descontar',
};

/** Config por motivo de Nota de Crédito (catálogo SUNAT 09). */
const NC_MOTIVOS: Record<string, Partial<MotivoConfig>> = {
    '01': { itemsMode: 'locked', allowAdd: false, allowRemove: false, aviso: 'Anulación de la operación: se anula el comprobante completo. Los ítems reflejan el total del original.' },
    '02': { itemsMode: 'locked', allowAdd: false, allowRemove: false, aviso: 'Anulación por error en el RUC: se anula el comprobante completo. Recuerda reemitir la factura con el RUC correcto.' },
    '03': { itemsMode: 'edit_description', allowAdd: false, allowRemove: false, aviso: 'Corrección por error en la descripción: edita solo el texto de los ítems; los montos no cambian.' },
    '04': { itemsMode: 'locked', allowAdd: false, allowRemove: false, showDescuentoGlobal: true, aviso: 'Descuento global: ingresa el descuento que se aplica sobre el total de la operación.' },
    '05': { itemsMode: 'edit_discount', allowAdd: false, allowRemove: false, aviso: 'Descuento por ítem: indica el descuento en cada ítem que corresponda.' },
    '06': { itemsMode: 'locked', allowAdd: false, allowRemove: false, aviso: 'Devolución total: se acredita el total del comprobante.' },
    '07': { itemsMode: 'edit_quantity', allowAdd: false, allowRemove: true, aviso: 'Devolución por ítem: ajusta las cantidades devueltas y quita los ítems que no se devuelven.' },
    '09': { itemsMode: 'edit_price', allowAdd: false, allowRemove: false, aviso: 'Disminución en el valor: ajusta el precio unitario de los ítems.' },
    '10': { itemsMode: 'free', allowAdd: true, allowRemove: true, aviso: 'Otros conceptos: carga libremente los ítems de la nota.' },
    '13': { itemsMode: 'free', allowAdd: true, allowRemove: true, aviso: 'Corrección en el monto de percepción: ajusta los ítems según la percepción a corregir.' },
};

export function ncMotivoConfig(codigo: string): MotivoConfig {
    return { ...NC_DEFAULT, ...(NC_MOTIVOS[codigo] ?? {}) };
}

const ND_DEFAULT: MotivoConfig = {
    itemsMode: 'free',
    allowAdd: true,
    allowRemove: true,
    showDescuentoGlobal: false,
    itemsTitulo: 'Conceptos de la nota de débito',
};

/** Config por motivo de Nota de Débito (catálogo SUNAT 10). */
const ND_MOTIVOS: Record<string, Partial<MotivoConfig>> = {
    '01': { itemsMode: 'free', allowAdd: true, allowRemove: true, itemsTitulo: 'Intereses por mora', aviso: 'Intereses por mora: agrega el/los conceptos de interés a cobrar.' },
    '02': { itemsMode: 'edit_price', allowAdd: false, allowRemove: false, itemsTitulo: 'Aumento en el valor', aviso: 'Aumento en el valor: ajusta el precio unitario de los ítems del comprobante.' },
    '03': { itemsMode: 'free', allowAdd: true, allowRemove: true, itemsTitulo: 'Penalidades / otros conceptos', aviso: 'Penalidades u otros cargos: agrega los conceptos a cobrar.' },
};

export function ndMotivoConfig(codigo: string): MotivoConfig {
    return { ...ND_DEFAULT, ...(ND_MOTIVOS[codigo] ?? {}) };
}
