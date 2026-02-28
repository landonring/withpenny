import { reactive } from 'vue';
import axios from 'axios';
import { authState } from './auth';

export const usageState = reactive({
    ready: false,
    busy: false,
    plan: 'starter',
    data: null,
    fetchedAt: 0,
    userId: null,
});

export function resetUsageState() {
    usageState.ready = false;
    usageState.busy = false;
    usageState.plan = 'starter';
    usageState.data = null;
    usageState.fetchedAt = 0;
    usageState.userId = null;
}

export async function ensureUsageStatus(force = false) {
    const currentUserId = authState.user?.id ?? null;
    const stale = usageState.fetchedAt && Date.now() - usageState.fetchedAt > 60 * 1000;
    if (!force && usageState.busy) {
        return usageState;
    }

    if (!authState.user) {
        resetUsageState();
        usageState.ready = true;
        return usageState;
    }

    // Prevent showing stale limits after account switching.
    if (usageState.userId !== currentUserId) {
        resetUsageState();
    }

    if (!force && usageState.ready && !stale && usageState.userId === currentUserId) {
        return usageState;
    }

    usageState.busy = true;
    try {
        const { data } = await axios.get('/api/usage');
        usageState.plan = data?.plan || 'starter';
        usageState.data = data || null;
        usageState.fetchedAt = Date.now();
        usageState.userId = currentUserId;
    } catch {
        usageState.plan = 'starter';
        usageState.data = null;
        usageState.fetchedAt = Date.now();
        usageState.userId = currentUserId;
    } finally {
        usageState.ready = true;
        usageState.busy = false;
    }

    return usageState;
}
