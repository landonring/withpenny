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
                        min="0"
                        step="0.01"
                        placeholder="0.00"
                        required
                    />
                </label>

                <p v-if="error" class="form-error">{{ error }}</p>

                <button class="primary-button" type="submit" :disabled="loading">
                    {{ loading ? 'Starting…' : 'Start journey' }}
                </button>
            </form>
        </div>
    </section>
</template>

<script setup>
import { ref } from 'vue';
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
</script>
