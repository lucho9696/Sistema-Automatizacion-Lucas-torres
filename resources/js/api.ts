import axios, { AxiosError } from 'axios';

export const http = axios.create({
    baseURL: '/',
    withCredentials: true,
    withXSRFToken: true,
    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
});

/** Clave única por envío de formulario: un doble clic o reintento no duplica ventas/cobros/gastos. */
export function idempotencyKey(): string {
    return crypto.randomUUID();
}

export function postOnce<T = unknown>(url: string, data: unknown, key: string) {
    return http.post<T>(url, data, { headers: { 'Idempotency-Key': key } });
}

export function errorMessage(e: unknown): string {
    const err = e as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
    const data = err.response?.data;
    if (data?.errors) {
        return Object.values(data.errors).flat().join(' ');
    }
    return data?.message ?? 'Error inesperado. Intente nuevamente.';
}

export interface Me {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'vendedor' | 'analista';
    abilities: string[];
    settings: {
        base_currency: string;
        currencies: string[];
        payment_methods: string[];
        display_timezone: string;
        purchase_cost_types: string[];
    };
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
}
