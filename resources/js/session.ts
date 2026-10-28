import { reactive } from 'vue';
import { http, type Me } from './api';

export const session = reactive<{ me: Me | null; loaded: boolean }>({ me: null, loaded: false });

export async function loadSession(): Promise<void> {
    try {
        session.me = (await http.get<Me>('/api/auth/me')).data;
    } catch {
        session.me = null;
    }
    session.loaded = true;
}

export async function login(email: string, password: string): Promise<void> {
    await http.get('/sanctum/csrf-cookie');
    session.me = (await http.post<Me>('/api/auth/login', { email, password })).data;
}

export async function logout(): Promise<void> {
    await http.post('/api/auth/logout');
    session.me = null;
}

export function can(ability: string): boolean {
    return !!session.me?.abilities.includes(ability);
}
