import { reactive } from 'vue';
import axios from 'axios';

export const authState = reactive({
    user: null,
    ready: false,
    busy: false,
    justLoggedIn: false,
});

export function applyAuthPayload(data) {
    authState.user = data.user;
    if (data.csrf_token) {
        window.axios.defaults.headers.common['X-CSRF-TOKEN'] = data.csrf_token;
    }
}

export async function initAuth() {
    if (authState.ready || authState.busy) {
        return;
    }

    authState.busy = true;

    try {
        const { data } = await axios.get('/api/me');
        applyAuthPayload(data);
        authState.justLoggedIn = false;
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
    const { data } = await axios.post('/api/login', { ...payload, remember: true });
    applyAuthPayload(data);
    authState.justLoggedIn = true;
    return data.user;
}

export async function register(payload) {
    const { data } = await axios.post('/api/register', payload);
    applyAuthPayload(data);
    authState.justLoggedIn = true;
    return data.user;
}

export async function updateProfile(payload) {
    const { data } = await axios.patch('/api/profile', payload);
    applyAuthPayload(data);
    return data.user;
}

export async function logout() {
    await axios.post('/api/logout');
    authState.user = null;
    authState.justLoggedIn = false;
}

export async function deleteAccount() {
    await axios.delete('/api/profile');
    authState.user = null;
    authState.justLoggedIn = false;
    localStorage.removeItem('penny.biometric.enabled');
    localStorage.removeItem('penny.biometric.dismissed');
}

export async function fetchDataSummary() {
    const { data } = await axios.get('/api/data-summary');
    return data;
}

export async function deleteImportedTransactions() {
    const { data } = await axios.delete('/api/transactions/imported');
    return data;
}
