<template>
    <section class="screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">Savings</p>
                <h1 class="screen-title">Add to your journey</h1>
            </div>
            <div class="accent-chip">Add</div>
        </div>

        <div v-if="journey && journey.status === 'active'" class="card">
            <div class="card-title">{{ journey.title }}</div>
            <p v-if="journey.description" class="card-sub">{{ journey.description }}</p>

            <form class="auth-form" @submit.prevent="handleSubmit">
                <label class="field">
                    <span>Amount</span>
                    <input
                        v-model="amount"
                        type="number"
                        inputmode="decimal"
                        min="0.01"
                        step="0.01"
                        placeholder="0.00"
                        required
                    />
                </label>

                <p v-if="success" class="muted">{{ success }}</p>
                <p v-if="error" class="form-error">{{ error }}</p>

                <button class="primary-button" type="submit" :disabled="loading">
                    {{ loading ? 'Saving…' : 'Add to journey' }}
                </button>
            </form>
        </div>

        <div v-else-if="journey" class="card">
            <div class="card-title">{{ journey.title }}</div>
            <p class="card-sub">
                This journey is paused or complete. You can resume it anytime before adding more.
            </p>
            <router-link class="ghost-button" :to="{ name: 'savings-edit', params: { id: journey.id } }">
                Manage journey
            </router-link>
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
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { addToJourney, getJourneyById, initSavings } from '../stores/savings';

const route = useRoute();
const router = useRouter();

const amount = ref('');
const loading = ref(false);
const error = ref('');
const success = ref('');

const journeyId = computed(() => route.params.id);
const journey = computed(() => getJourneyById(journeyId.value));

onMounted(() => {
    initSavings();
});

const handleSubmit = async () => {
    loading.value = true;
    error.value = '';
    success.value = '';

    try {
        const updated = await addToJourney(journeyId.value, amount.value);
        success.value = updated.status === 'completed' ? 'You reached this moment.' : 'A small step forward.';
        setTimeout(() => {
            router.push({ name: 'savings' });
        }, 600);
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to add that right now.';
    } finally {
        loading.value = false;
    }
};
</script>
