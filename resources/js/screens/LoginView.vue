<template>
    <section class="screen auth-screen">
        <div class="auth-card">
            <div>
                <p class="eyebrow">Welcome back</p>
                <h1 class="screen-title">Log in to Penny</h1>
                <p class="card-sub">Gentle and private, just for you.</p>
            </div>

            <form class="auth-form" @submit.prevent="handleSubmit">
                <label class="field">
                    <span>Email</span>
                    <input v-model="form.email" type="email" autocomplete="email" required />
                </label>

                <label class="field">
                    <span>Password</span>
                    <input v-model="form.password" type="password" autocomplete="current-password" required />
                </label>

                <p v-if="error" class="form-error">{{ error }}</p>

                <button class="primary-button" type="submit" :disabled="loading">
                    {{ loading ? 'Signing in…' : 'Log in' }}
                </button>
            </form>

            <p class="muted">
                New here?
                <router-link to="/register">Create an account</router-link>
            </p>
        </div>
    </section>
</template>

<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { login } from '../stores/auth';

const router = useRouter();
const route = useRoute();

const form = ref({
    email: '',
    password: '',
});

const error = ref('');
const loading = ref(false);

const handleSubmit = async () => {
    error.value = '';
    loading.value = true;

    try {
        await login(form.value);
        const redirect = route.query.redirect || '/';
        router.push(redirect);
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to log in right now.';
    } finally {
        loading.value = false;
    }
};
</script>
