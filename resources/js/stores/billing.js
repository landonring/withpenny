import { reactive } from 'vue';
import axios from 'axios';
import { authState } from './auth';

async function ensureCsrf() {
    if (axios?.defaults?.headers?.common?.['X-CSRF-TOKEN']) {
        return;
    }
    try {
        const { data } = await axios.get('/api/csrf');
        if (data?.csrf_token) {
            axios.defaults.headers.common['X-CSRF-TOKEN'] = data.csrf_token;
        }
    } catch {
        // ignore
    }
}

export async function fetchBillingPlans() {
    const { data } = await axios.get('/api/billing/plans');
    return data;
}

export async function fetchBillingStatus() {
    const { data } = await axios.get('/api/billing/status');
    return data;
}

export const billingState = reactive({
    ready: false,
    busy: false,
    plan: 'starter',
    basePlan: 'starter',
    effectivePlan: 'starter',
    interval: 'monthly',
    status: 'none',
    active: false,
    onTrial: false,
    endsAt: null,
    pendingChange: null,
    userId: null,
    fetchedAt: 0,
});

export function applyBillingStatus(data, userId = null) {
    billingState.plan = data?.effective_plan || data?.plan || 'starter';
    billingState.basePlan = data?.base_plan || data?.plan || 'starter';
    billingState.effectivePlan = data?.effective_plan || data?.plan || 'starter';
    billingState.interval = data?.interval || 'monthly';
    billingState.status = data?.status || 'none';
    billingState.active = !!data?.active;
    billingState.onTrial = !!data?.on_trial;
    billingState.endsAt = data?.ends_at || null;
    billingState.pendingChange = data?.pending_change || null;
    billingState.userId = userId ?? billingState.userId;
    billingState.fetchedAt = Date.now();
}

export async function ensureBillingStatus(force = false) {
    const userId = authState.user?.id || null;
    const stale = billingState.fetchedAt && Date.now() - billingState.fetchedAt > 2 * 60 * 1000;
    const userChanged = billingState.userId !== userId;
    const shouldRefresh = force || authState.justLoggedIn || userChanged || stale;

    if (!shouldRefresh && (billingState.ready || billingState.busy)) {
        return billingState;
    }

    if (!authState.user) {
        billingState.plan = 'starter';
        billingState.basePlan = 'starter';
        billingState.effectivePlan = 'starter';
        billingState.interval = 'monthly';
        billingState.status = 'none';
        billingState.active = false;
        billingState.onTrial = false;
        billingState.endsAt = null;
        billingState.pendingChange = null;
        billingState.userId = null;
        billingState.fetchedAt = 0;
        billingState.ready = true;
        billingState.busy = false;
        return billingState;
    }

    billingState.busy = true;

    try {
        const data = await fetchBillingStatus();
        applyBillingStatus(data, userId);
    } catch {
        billingState.plan = 'starter';
        billingState.basePlan = 'starter';
        billingState.effectivePlan = 'starter';
        billingState.interval = 'monthly';
        billingState.status = 'none';
        billingState.active = false;
        billingState.onTrial = false;
        billingState.endsAt = null;
        billingState.pendingChange = null;
        billingState.userId = userId;
        billingState.fetchedAt = Date.now();
    } finally {
        billingState.ready = true;
        billingState.busy = false;
    }

    return billingState;
}

export async function startCheckout(plan, interval) {
    await ensureCsrf();
    const { data } = await axios.post('/api/billing/checkout', { plan, interval });
    return data;
}

export async function openBillingPortal() {
    await ensureCsrf();
    const { data } = await axios.post('/api/billing/portal');
    return data;
}

export async function completeCheckout(sessionId) {
    if (!sessionId) return null;
    await ensureCsrf();
    const { data } = await axios.post('/api/billing/complete', { session_id: sessionId });
    return data;
}

export async function cancelSubscription(options = {}) {
    await ensureCsrf();
    const { data } = await axios.post('/api/billing/cancel', options);
    return data;
}

export async function resumeSubscription() {
    await ensureCsrf();
    const { data } = await axios.post('/api/billing/resume');
    return data;
}
