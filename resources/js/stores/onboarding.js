import { reactive } from 'vue';
import axios from 'axios';
import { authState } from './auth';

export const onboardingState = reactive({
    ready: false,
    busy: false,
    mode: false,
    step: 0,
    completed: false,
    startedAt: null,
    targetPath: null,
    instructions: null,
});

function resetState() {
    onboardingState.mode = false;
    onboardingState.step = 0;
    onboardingState.completed = false;
    onboardingState.startedAt = null;
    onboardingState.targetPath = null;
    onboardingState.instructions = null;
    if (authState.user) {
        authState.user.onboarding_mode = false;
        authState.user.onboarding_step = 0;
        authState.user.onboarding_started_at = null;
    }
}

export function resetOnboardingState() {
    resetState();
    onboardingState.ready = false;
    onboardingState.busy = false;
}

function applyPayload(payload) {
    onboardingState.mode = !!payload?.mode;
    onboardingState.step = Number.isFinite(Number(payload?.step)) ? Number(payload.step) : 0;
    onboardingState.completed = !!payload?.completed;
    onboardingState.startedAt = payload?.started_at || null;
    onboardingState.targetPath = payload?.target_path || null;
    onboardingState.instructions = payload?.instructions || null;
    if (authState.user) {
        authState.user.onboarding_mode = onboardingState.mode;
        authState.user.onboarding_step = onboardingState.step;
        authState.user.onboarding_completed = onboardingState.completed;
        authState.user.onboarding_started_at = onboardingState.startedAt;
    }
}

export async function ensureOnboardingStatus(force = false) {
    if (!authState.user) {
        resetState();
        onboardingState.ready = true;
        onboardingState.busy = false;
        return onboardingState;
    }

    if (!force && onboardingState.ready) {
        return onboardingState;
    }

    if (onboardingState.busy) {
        return onboardingState;
    }

    onboardingState.busy = true;
    try {
        const { data } = await axios.get('/api/onboarding/status');
        applyPayload(data || {});
    } catch {
        resetState();
    } finally {
        onboardingState.ready = true;
        onboardingState.busy = false;
    }

    return onboardingState;
}

export function routeAllowedDuringOnboarding(path) {
    if (!onboardingState.mode) return true;

    const clean = String(path || '').split('?')[0];

    if (onboardingState.step === 0) {
        return clean === '/app'
            || clean === '/statements/scan'
            || clean === '/insights'
            || clean === '/chat'
            || clean === '/savings';
    }
    if (onboardingState.step === 1) return clean === '/statements/scan';
    if (onboardingState.step === 2) return /^\/statements\/\d+\/review$/.test(clean) || clean === '/statements/scan';
    if (onboardingState.step === 3) return clean === '/insights';
    if (onboardingState.step === 4) return clean === '/chat' || clean === '/savings';

    return clean === '/app';
}

export async function advanceOnboarding() {
    const { data } = await axios.post('/api/onboarding/advance');
    applyPayload(data || {});
    onboardingState.ready = true;
    return onboardingState;
}

export async function finishOnboarding() {
    const { data } = await axios.post('/api/onboarding/finish');
    applyPayload(data || {});
    onboardingState.ready = true;
    return onboardingState;
}

export async function skipOnboarding() {
    const { data } = await axios.post('/api/onboarding/skip');
    applyPayload(data || {});
    onboardingState.ready = true;
    return onboardingState;
}

export async function replayOnboarding() {
    const { data } = await axios.post('/api/onboarding/replay');
    applyPayload(data || {});
    onboardingState.ready = true;
    return onboardingState;
}
