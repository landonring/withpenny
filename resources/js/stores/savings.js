import { reactive } from 'vue';
import axios from 'axios';
import { authState, ensureAuthReady } from './auth';
import { applyFutureContribution } from './transactions';

const state = reactive({
    journeys: [],
    loading: false,
    ready: false,
    userId: null,
});

function setJourneys(journeys) {
    state.journeys = journeys;
}

function upsertJourney(journey) {
    const index = state.journeys.findIndex((item) => String(item.id) === String(journey.id));
    if (index === -1) {
        state.journeys = [journey, ...state.journeys];
    } else {
        state.journeys = state.journeys.map((item) => (String(item.id) === String(journey.id) ? journey : item));
    }
}

export async function fetchJourneys() {
    await ensureAuthReady();
    if (!authState.user) return;

    state.loading = true;
    try {
        const { data } = await axios.get('/api/savings-journeys');
        setJourneys(data.journeys || []);
    } finally {
        state.loading = false;
    }
}

export async function initSavings() {
    await ensureAuthReady();
    const userId = authState.user?.id;
    if (!userId) return;
    if (state.userId !== userId) {
        state.userId = userId;
        state.ready = false;
    }
    if (state.ready) return;
    state.ready = true;
    await fetchJourneys();
}

export function getJourneyById(id) {
    return state.journeys.find((journey) => String(journey.id) === String(id));
}

export async function createJourney(payload) {
    await ensureAuthReady();
    const { data } = await axios.post('/api/savings-journeys', payload);
    upsertJourney(data.journey);
    return data.journey;
}

export async function updateJourney(id, payload) {
    await ensureAuthReady();
    const { data } = await axios.patch(`/api/savings-journeys/${id}`, payload);
    upsertJourney(data.journey);
    return data.journey;
}

export async function addToJourney(id, amount) {
    await ensureAuthReady();
    const { data } = await axios.post(`/api/savings-journeys/${id}/add`, { amount });
    upsertJourney(data.journey);
    applyFutureContribution(amount);
    return data.journey;
}

export async function setJourneyStatus(id, status) {
    await ensureAuthReady();

    const endpoint = {
        active: 'resume',
        paused: 'pause',
        completed: 'complete',
    }[status];

    if (!endpoint) {
        throw new Error('Invalid status');
    }

    const { data } = await axios.post(`/api/savings-journeys/${id}/${endpoint}`);
    upsertJourney(data.journey);
    return data.journey;
}

export { state as savingsState };
