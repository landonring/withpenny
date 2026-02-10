<template>
    <section class="screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">Savings</p>
                <h1 class="screen-title">Start a journey</h1>
            </div>
            <div class="accent-chip">New</div>
        </div>

        <div class="card">
            <p class="card-sub">You can change this later. Even small steps count.</p>

            <form class="auth-form" @submit.prevent="handleSubmit">
                <div class="quick-start">
                    <span class="field-hint">Quick option</span>
                    <div class="quick-row">
                        <button
                            class="quick-pill"
                            type="button"
                            @click="applyPreset('Emergency fund', 'A calm buffer for the unexpected')"
                        >
                            Emergency fund
                        </button>
                    </div>
                </div>

                <label class="field">
                    <span>Title</span>
                    <input v-model="form.title" type="text" placeholder="Beach trip" required />
                </label>

                <label class="field">
                    <span>Description (optional)</span>
                    <input v-model="form.description" type="text" placeholder="Something calm and sunny" />
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

                <button class="primary-button" type="submit" :disabled="loading">
                    {{ loading ? 'Starting…' : 'Start journey' }}
                </button>
            </form>
        </div>
    </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { createJourney } from '../stores/savings';

const router = useRouter();

const form = ref({
    title: '',
    description: '',
    target_amount: '',
});

const loading = ref(false);
const error = ref('');

const handleSubmit = async () => {
    loading.value = true;
    error.value = '';

    try {
                await createJourney({
                    title: form.value.title,
                    description: form.value.description || null,
                    target_amount: form.value.target_amount,
                });
        router.push({ name: 'savings' });
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to start this journey right now.';
    } finally {
        loading.value = false;
    }
};

const applyPreset = (title, description) => {
    form.value.title = title;
    if (!form.value.description) {
        form.value.description = description;
    }
    if (title.toLowerCase().includes('emergency fund')) {
        form.value.target_amount = form.value.target_amount ? form.value.target_amount : 20000;
    }
};

const isEmergency = computed(() => form.value.title.toLowerCase().includes('emergency fund'));
</script>
