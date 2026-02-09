<template>
    <section class="savings-screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">Savings</p>
                <h1 class="screen-title">A gentle journey forward</h1>
            </div>
            <div class="accent-chip">Journeys</div>
        </div>

        <div class="card savings-intro">
            <p class="card-sub">
                Saving here is personal and flexible. You can move at your own pace.
            </p>
            <router-link class="primary-button" :to="{ name: 'savings-new' }">
                Start a journey
            </router-link>
        </div>

        <div class="journey-section">
            <div class="section-header">
                <div class="card-title">Active journeys</div>
                <div class="muted">{{ activeJourneys.length }}</div>
            </div>

            <div v-if="loading" class="muted">Gathering your journeys…</div>
            <div v-else-if="!activeJourneys.length" class="card empty-state">
                <div class="card-title">No journeys yet</div>
                <p class="card-sub">If it feels right, start something small.</p>
                <router-link class="ghost-button" :to="{ name: 'savings-new' }">
                    Start a journey
                </router-link>
            </div>
            <div v-else class="journey-grid">
                <div v-for="journey in activeJourneys" :key="journey.id" class="card journey-card">
                    <div class="journey-header">
                        <div>
                            <div class="card-title">{{ journey.title }}</div>
                            <p v-if="journey.description" class="card-sub">{{ journey.description }}</p>
                        </div>
                        <div class="journey-status">Active</div>
                    </div>

                    <div class="milestone-bar">
                        <span
                            v-for="index in 3"
                            :key="index"
                            :class="['milestone-seg', { active: index <= activeSegments(journey) }]"
                        ></span>
                    </div>
                    <div class="milestone-label">{{ milestoneLabel(journey) }}</div>

                    <div v-if="detailsOpen[journey.id]" class="journey-details">
                        <div class="detail-row">
                            <span>Saved</span>
                            <span>{{ formatCurrency(journey.current_amount) }}</span>
                        </div>
                        <div v-if="journey.target_amount" class="detail-row">
                            <span>Target</span>
                            <span>{{ formatCurrency(journey.target_amount) }}</span>
                        </div>
                    </div>

                    <div class="journey-actions">
                        <button class="ghost-button" type="button" @click="toggleDetails(journey.id)">
                            {{ detailsOpen[journey.id] ? 'Hide details' : 'Show details' }}
                        </button>
                        <router-link class="ghost-button" :to="{ name: 'savings-edit', params: { id: journey.id } }">
                            Edit
                        </router-link>
                        <router-link class="primary-button" :to="{ name: 'savings-add', params: { id: journey.id } }">
                            Add to journey
                        </router-link>
                    </div>
                </div>
            </div>
        </div>

        <div class="journey-section">
            <button class="section-toggle" type="button" @click="showPaused = !showPaused">
                <span>Paused journeys</span>
                <span class="muted">{{ pausedJourneys.length }}</span>
            </button>
            <div v-if="showPaused" class="journey-grid">
                <div v-for="journey in pausedJourneys" :key="journey.id" class="card journey-card paused">
                    <div class="journey-header">
                        <div>
                            <div class="card-title">{{ journey.title }}</div>
                            <p v-if="journey.description" class="card-sub">{{ journey.description }}</p>
                        </div>
                        <div class="journey-status">Paused</div>
                    </div>
                    <p class="muted">You can come back to this anytime.</p>
                    <div class="journey-actions">
                        <router-link class="ghost-button" :to="{ name: 'savings-edit', params: { id: journey.id } }">
                            Edit
                        </router-link>
                        <button class="primary-button" type="button" @click="resumeJourney(journey.id)">
                            Resume
                        </button>
                    </div>
                </div>
                <div v-if="!pausedJourneys.length" class="muted">No paused journeys right now.</div>
            </div>
        </div>

        <div class="journey-section">
            <button class="section-toggle" type="button" @click="showCompleted = !showCompleted">
                <span>Completed journeys</span>
                <span class="muted">{{ completedJourneys.length }}</span>
            </button>
            <div v-if="showCompleted" class="journey-grid">
                <div v-for="journey in completedJourneys" :key="journey.id" class="card journey-card completed">
                    <div class="journey-header">
                        <div>
                            <div class="card-title">{{ journey.title }}</div>
                            <p v-if="journey.description" class="card-sub">{{ journey.description }}</p>
                        </div>
                        <div class="journey-status">Complete</div>
                    </div>
                    <p class="muted">You reached this moment.</p>
                    <div class="journey-actions">
                        <router-link class="ghost-button" :to="{ name: 'savings-edit', params: { id: journey.id } }">
                            View
                        </router-link>
                    </div>
                </div>
                <div v-if="!completedJourneys.length" class="muted">No completed journeys yet.</div>
            </div>
        </div>
    </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { savingsState, fetchJourneys, initSavings, setJourneyStatus } from '../stores/savings';

const showPaused = ref(false);
const showCompleted = ref(false);
const detailsOpen = ref({});

onMounted(() => {
    initSavings();
});

const loading = computed(() => savingsState.loading);
const journeys = computed(() => savingsState.journeys);

const activeJourneys = computed(() => journeys.value.filter((journey) => journey.status === 'active'));
const pausedJourneys = computed(() => journeys.value.filter((journey) => journey.status === 'paused'));
const completedJourneys = computed(() => journeys.value.filter((journey) => journey.status === 'completed'));

const toggleDetails = (id) => {
    detailsOpen.value = { ...detailsOpen.value, [id]: !detailsOpen.value[id] };
};

const resumeJourney = async (id) => {
    await setJourneyStatus(id, 'active');
    await fetchJourneys();
};

const activeSegments = (journey) => {
    const target = Number.parseFloat(journey.target_amount || 0);
    const current = Number.parseFloat(journey.current_amount || 0);

    if (target > 0) {
        const ratio = Math.min(current / target, 1);
        if (ratio === 0) return 0;
        if (ratio < 0.34) return 1;
        if (ratio < 0.67) return 2;
        return 3;
    }

    if (current > 0) return 1;
    return 0;
};

const milestoneLabel = (journey) => {
    if (journey.status === 'completed') return 'Journey complete';
    if (journey.status === 'paused') return 'Paused for now';

    const target = Number.parseFloat(journey.target_amount || 0);
    const current = Number.parseFloat(journey.current_amount || 0);

    if (target > 0) {
        const ratio = current / target;
        if (ratio === 0) return 'First step';
        if (ratio < 0.34) return 'First step';
        if (ratio < 0.67) return 'Building momentum';
        return 'Almost there';
    }

    return current > 0 ? 'Building momentum' : 'First step';
};

const formatCurrency = (value) => {
    const amount = Number.parseFloat(value) || 0;
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 2,
    }).format(amount);
};
</script>
