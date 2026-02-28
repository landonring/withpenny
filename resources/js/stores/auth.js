import { reactive } from 'vue';
import axios from 'axios';

export const authState = reactive({
    user: null,
    ready: false,
    busy: false,
    justLoggedIn: false,
    impersonating: false,
});

let lastUserId = null;

const clearLocalUserData = () => {
    try {
        Object.keys(localStorage)
            .filter((key) => key.startsWith('penny.'))
            .forEach((key) => localStorage.removeItem(key));
    } catch {
        // ignore storage errors
    }
};

export function applyAuthPayload(data) {
    const nextUserId = data?.user?.id || null;
    if (lastUserId && nextUserId && lastUserId !== nextUserId) {
        clearLocalUserData();
    }
    lastUserId = nextUserId;
    authState.user = data.user;
    authState.impersonating = !!data.impersonating;
    if (data.csrf_token) {
        window.axios.defaults.headers.common['X-CSRF-TOKEN'] = data.csrf_token;
    }
}

async function ensureCsrf(force = false) {
    if (!force && window.axios?.defaults?.headers?.common?.['X-CSRF-TOKEN']) {
        return;
    }
    try {
        const { data } = await axios.get('/api/csrf');
        if (data?.csrf_token) {
            window.axios.defaults.headers.common['X-CSRF-TOKEN'] = data.csrf_token;
        }
    } catch {
        // ignore
    }
}

async function withCsrfRetry(requestFactory) {
    await ensureCsrf();
    try {
        return await requestFactory();
    } catch (error) {
        if (error?.response?.status === 419) {
            await ensureCsrf(true);
            return await requestFactory();
        }
        throw error;
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
    const { data } = await withCsrfRetry(() => axios.post('/api/login', { ...payload, remember: true }));
    applyAuthPayload(data);
    authState.justLoggedIn = true;
    return data.user;
}

export async function register(payload) {
    const { data } = await withCsrfRetry(() => axios.post('/api/register', payload));
    applyAuthPayload(data);
    authState.justLoggedIn = true;
    return data.user;
}

export async function updateProfile(payload) {
    const { data } = await withCsrfRetry(() => axios.patch('/api/profile', payload));
    applyAuthPayload(data);
    return data.user;
}

export async function updateLifePhase(payload) {
    const { data } = await withCsrfRetry(() => axios.put('/api/user/profile', payload));
    applyAuthPayload(data);
    return data.user;
}

export async function logout() {
    await withCsrfRetry(() => axios.post('/api/logout'));
    authState.user = null;
    authState.justLoggedIn = false;
    authState.impersonating = false;
    lastUserId = null;
    clearLocalUserData();
}

export async function deleteAccount() {
    await withCsrfRetry(() => axios.delete('/api/profile'));
    authState.user = null;
    authState.justLoggedIn = false;
    authState.impersonating = false;
    lastUserId = null;
    clearLocalUserData();
}

export async function stopImpersonation() {
    const { data } = await withCsrfRetry(() => axios.post('/admin/impersonate/stop'));
    applyAuthPayload(data);
    authState.justLoggedIn = false;
    return data.user;
}

export async function fetchDataSummary() {
    const { data } = await axios.get('/api/data-summary');
    return data;
}

export async function deleteImportedTransactions() {
    const { data } = await withCsrfRetry(() => axios.delete('/api/transactions/imported'));
    return data;
}

export async function deleteAllTransactions() {
    const { data } = await withCsrfRetry(() => axios.delete('/api/transactions/all'));
    return data;
}
