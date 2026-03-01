<template>
    <section class="screen auth-screen">
        <div class="auth-card">
            <div>
                <p class="eyebrow">Account recovery</p>
                <h1 class="screen-title">Reset your password</h1>
                <p class="card-sub">Enter your email and we’ll send a secure reset link.</p>
            </div>

            <form v-if="!sent" class="auth-form" @submit.prevent="handleSubmit">
                <label class="field">
                    <span>Email</span>
                    <input v-model="form.email" type="email" autocomplete="email" required />
                </label>

                <p v-if="error" class="form-error">{{ error }}</p>

                <button class="primary-button" type="submit" :disabled="loading">
                    {{ loading ? 'Sending…' : 'Send reset link' }}
                </button>
            </form>

            <div v-else class="auth-form">
                <p class="faceid-hint">
                    If that email exists, reset instructions were sent.
                </p>
            </div>

            <p class="muted">
                Remembered it?
                <router-link :to="{ name: 'login' }">Back to login</router-link>
            </p>
        </div>
    </section>
</template>

<script setup>
import { ref } from 'vue';
import { requestPasswordReset } from '../stores/auth';

const form = ref({
    email: '',
});

const loading = ref(false);
const sent = ref(false);
const error = ref('');

const handleSubmit = async () => {
    loading.value = true;
    error.value = '';
    try {
        await requestPasswordReset(form.value);
        sent.value = true;
    } catch (err) {
        const message = err?.response?.data?.message;
        const errors = err?.response?.data?.errors;
        error.value = message || (errors ? Object.values(errors).flat().join(' ') : 'Unable to send reset link right now.');
    } finally {
        loading.value = false;
    }
};
</script>
