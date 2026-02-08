import { reactive } from 'vue';
import axios from 'axios';

export const authState = reactive({
    user: null,
    ready: false,
    busy: false,
});

export async function initAuth() {
    if (authState.ready || authState.busy) {
        return;
    }

    authState.busy = true;

    try {
        const { data } = await axios.get('/api/me');
        authState.user = data.user;
    } catch (error) {
        authState.user = null;
    } finally {
        authState.ready = true;
        authState.busy = false;
    }
}

export async function ensureAuthReady() {
    if (!authState.ready) {
        await initAuth();
    }
}

export async function login(payload) {
    const { data } = await axios.post('/api/login', payload);
    authState.user = data.user;
    return data.user;
}

export async function register(payload) {
    const { data } = await axios.post('/api/register', payload);
    authState.user = data.user;
    return data.user;
}

export async function logout() {
    await axios.post('/api/logout');
    authState.user = null;
}
