<template>
    <section class="screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">Savings</p>
                <h1 class="screen-title">Edit your journey</h1>
            </div>
            <div class="accent-chip">Edit</div>
        </div>

        <div v-if="journey" class="card">
            <form class="auth-form" @submit.prevent="handleSubmit">
                <label class="field">
                    <span>Title</span>
                    <input v-model="form.title" type="text" required />
                </label>

                <label class="field">
                    <span>Description (optional)</span>
                    <input v-model="form.description" type="text" />
                </label>

                <label class="field">
                    <span>Target amount</span>
                    <input
                        v-model="form.target_amount"
                        type="number"
                        inputmode="decimal"
                        :min="isEmergency ? 20000 : 0.01"
                        step="0.01"
                        placeholder="0.00"
                        required
                    />
                </label>
                <p v-if="isEmergency" class="muted">Emergency fund targets start at $20,000.</p>

                <p v-if="error" class="form-error">{{ error }}</p>
                <p v-if="success" class="muted">{{ success }}</p>

                <button class="primary-button" type="submit" :disabled="loading">
                    {{ loading ? 'Saving…' : 'Save changes' }}
                </button>
            </form>
        </div>

        <div v-if="journey" class="card">
            <div class="card-title">Journey status</div>
            <p class="card-sub" v-if="journey.status === 'paused'">
                You can come back to this anytime.
            </p>
            <p class="card-sub" v-else-if="journey.status === 'completed'">
                This journey is complete.
            </p>
            <p class="card-sub" v-else>
                Keep it moving whenever it feels right.
            </p>

            <div class="journey-actions">
                <button
                    v-if="journey.status === 'active'"
                    class="ghost-button"
                    type="button"
                    @click="setStatus('paused')"
                >
                    Pause journey
                </button>
                <button
                    v-if="journey.status === 'paused'"
                    class="ghost-button"
                    type="button"
                    @click="setStatus('active')"
                >
                    Resume journey
                </button>
                <button
                    v-if="journey.status !== 'completed'"
                    class="primary-button"
                    type="button"
                    @click="setStatus('completed')"
                >
                    Mark complete
                </button>
            </div>
        </div>

        <div v-if="journey" class="card">
            <div class="card-title">Delete journey</div>
            <p class="card-sub">You can remove this journey anytime.</p>
            <button class="danger-button" type="button" @click="handleDelete">
                Delete journey
            </button>
        </div>

        <div v-else class="card">
            <div class="card-title">Journey not found</div>
            <p class="card-sub">Head back to your savings list to choose a journey.</p>
            <router-link class="ghost-button" :to="{ name: 'savings' }">
                Back to savings
            </router-link>
        </div>
    </section>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { deleteJourney, getJourneyById, initSavings, setJourneyStatus, updateJourney } from '../stores/savings';

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const error = ref('');
const success = ref('');

const journeyId = computed(() => route.params.id);
const journey = computed(() => getJourneyById(journeyId.value));

const form = ref({
    title: '',
    description: '',
    target_amount: '',
});

const isEmergency = computed(() => form.value.title.toLowerCase().includes('emergency fund'));

onMounted(() => {
    initSavings();
});

watch(
    () => journey.value,
    (value) => {
        if (!value) return;
        form.value.title = value.title;
        form.value.description = value.description || '';
        form.value.target_amount = value.target_amount || '';
    },
    { immediate: true }
);

const handleSubmit = async () => {
    loading.value = true;
    error.value = '';
    success.value = '';

    try {
        await updateJourney(journeyId.value, {
            title: form.value.title,
            description: form.value.description || null,
            target_amount: form.value.target_amount,
        });
        success.value = 'Saved.';
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to save changes right now.';
    } finally {
        loading.value = false;
    }
};

const setStatus = async (status) => {
    try {
        await setJourneyStatus(journeyId.value, status);
        if (status === 'completed') {
            router.push({ name: 'savings' });
        }
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to update this journey.';
    }
};

const handleDelete = async () => {
    if (!journey.value) return;
    const ok = window.confirm('Delete this journey? This can’t be undone.');
    if (!ok) return;
    try {
        await deleteJourney(journeyId.value);
        router.push({ name: 'savings' });
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to delete this journey.';
    }
};
</script>
