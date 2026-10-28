/**
 * Solo presentación. Los importes llegan como string decimal exacto desde la API;
 * no se hacen cálculos financieros en el frontend.
 */
export function money(value: string | null | undefined, currency = ''): string {
    if (value === null || value === undefined || value === '') return '—';
    const negative = value.startsWith('-');
    const [int, dec = '00'] = value.replace('-', '').split('.');
    const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return `${negative ? '-' : ''}${currency ? currency + ' ' : ''}${grouped},${dec.padEnd(2, '0').slice(0, 2)}`;
}

export function date(value: string | null | undefined): string {
    if (!value) return '—';
    const [y, m, d] = value.slice(0, 10).split('-');
    return `${d}/${m}/${y}`;
}

export function today(): string {
    return new Date().toLocaleDateString('en-CA', { timeZone: 'America/Argentina/Cordoba' });
}

const LABELS: Record<string, string> = {
    draft: 'borrador',
    received: 'recibida',
    cancelled: 'cancelada',
};

export function label(status: string): string {
    return LABELS[status] ?? status;
}
