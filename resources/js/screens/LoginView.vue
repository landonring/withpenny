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
                    <div class="field-row">
                        <input
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            autocomplete="current-password"
                            required
                        />
                        <button class="field-toggle" type="button" @click="showPassword = !showPassword">
                            {{ showPassword ? 'Hide' : 'Show' }}
                        </button>
                    </div>
                </label>

                <p v-if="error" class="form-error">{{ error }}</p>

                <button class="primary-button" type="submit" :disabled="loading">
                    {{ loading ? 'Signing in…' : 'Log in' }}
                </button>

                <button
                    v-if="biometricAvailable"
                    class="faceid-button"
                    type="button"
                    :disabled="biometricBusy || !biometricsState.enabled"
                    @click="handleBiometricLogin"
                >
                    <span class="faceid-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                            <rect x="4" y="4" width="16" height="16" rx="4" />
                            <path d="M9 8v2" />
                            <path d="M15 8v2" />
                            <path d="M9 16v-2" />
                            <path d="M15 16v-2" />
                            <path d="M8.5 12c1.2 1.1 5.8 1.1 7 0" />
                        </svg>
                    </span>
                    <span>{{ biometricBusy ? 'Checking…' : 'Face ID' }}</span>
                </button>

                <p v-if="biometricError" class="form-error">{{ biometricError }}</p>
            </form>

            <p class="muted">
                New here?
                <router-link to="/register">Create an account</router-link>
            </p>
        </div>
    </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { login } from '../stores/auth';
import {
    biometricsState,
    checkBiometricSupport,
    isBiometricEnabledLocal,
    loginWithBiometrics,
} from '../stores/biometrics';

const router = useRouter();
const route = useRoute();

const form = ref({
    email: '',
    password: '',
});

const error = ref('');
const loading = ref(false);
const showPassword = ref(false);
const biometricError = ref('');
const biometricBusy = ref(false);

const biometricAvailable = computed(() => biometricsState.supported);

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

const handleBiometricLogin = async () => {
    biometricError.value = '';
    biometricBusy.value = true;

    try {
        await loginWithBiometrics();
        const redirect = route.query.redirect || '/';
        router.push(redirect);
    } catch (err) {
        biometricError.value = err?.response?.data?.message || err?.message || 'Face ID didn’t work this time.';
    } finally {
        biometricBusy.value = false;
    }
};

onMounted(async () => {
    biometricsState.enabled = isBiometricEnabledLocal();
    await checkBiometricSupport();
});

</script>
