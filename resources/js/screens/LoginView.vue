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
                        <button
                            class="field-toggle field-toggle-eye"
                            type="button"
                            :aria-label="showPassword ? 'Hide password' : 'Show password'"
                            @click="showPassword = !showPassword"
                        >
                            <svg v-if="!showPassword" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 5C6.6 5 2.2 8.4 1 12c1.2 3.6 5.6 7 11 7s9.8-3.4 11-7c-1.2-3.6-5.6-7-11-7zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8z"/>
                                <circle cx="12" cy="12" r="2.2"/>
                            </svg>
                            <svg v-else viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M3.3 2 2 3.3l3 3C3.2 7.7 1.8 9.7 1 12c1.2 3.6 5.6 7 11 7 2.1 0 4-.5 5.6-1.4l3.1 3.1 1.3-1.3L3.3 2zm8.7 14a4 4 0 0 1-4-4c0-.6.1-1.1.3-1.6l5.3 5.3c-.5.2-1 .3-1.6.3zM12 5c5.4 0 9.8 3.4 11 7-.5 1.6-1.6 3-3 4.2l-1.4-1.4c.8-.8 1.5-1.8 1.9-2.8-1.2-3-4.8-5.4-8.5-5.4-1.3 0-2.5.3-3.6.8L7.1 6C8.6 5.4 10.2 5 12 5z"/>
                            </svg>
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
                    <span>{{ biometricBusy ? 'Checking…' : 'Use passkey' }}</span>
                </button>

                <p v-if="biometricHint" class="faceid-hint">{{ biometricHint }}</p>
                <p v-if="biometricError" class="form-error">{{ biometricError }}</p>
            </form>

            <p class="muted">
                New here?
                <router-link :to="{ name: 'register', query: billingQuery }">Create an account</router-link>
            </p>
        </div>
    </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { login } from '../stores/auth';
import { startCheckout } from '../stores/billing';
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
const biometricHint = ref('');
const biometricBusy = ref(false);

const biometricAvailable = computed(() => biometricsState.supported);
const billingQuery = computed(() => {
    const query = {};
    if (route.query.plan) query.plan = route.query.plan;
    if (route.query.interval) query.interval = route.query.interval;
    if (route.query.redirect) query.redirect = route.query.redirect;
    return query;
});

const getPlanIntent = () => {
    const plan = String(route.query.plan || '');
    if (!['pro', 'premium'].includes(plan)) {
        return null;
    }
    const interval = route.query.interval === 'yearly' ? 'yearly' : 'monthly';
    return { plan, interval };
};

const handlePostLogin = async () => {
    const intent = getPlanIntent();
    if (intent) {
        const data = await startCheckout(intent.plan, intent.interval);
        if (data?.url) {
            window.location.href = data.url;
            return true;
        }
    }
    return false;
};

const handleSubmit = async () => {
    error.value = '';
    loading.value = true;

    try {
        await login(form.value);
        const handled = await handlePostLogin();
        if (handled) return;
        const redirect = route.query.redirect || '/app';
        router.push(redirect);
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to log in right now.';
    } finally {
        loading.value = false;
    }
};

const handleBiometricLogin = async () => {
    biometricError.value = '';
    biometricHint.value = '';

    biometricBusy.value = true;

    try {
        await loginWithBiometrics();
        const handled = await handlePostLogin();
        if (handled) return;
        const redirect = route.query.redirect || '/app';
        router.push(redirect);
    } catch (err) {
        biometricError.value = err?.response?.data?.message || err?.message || 'Passkey didn’t work this time.';
    } finally {
        biometricBusy.value = false;
    }
};

onMounted(async () => {
    const localEnabled = isBiometricEnabledLocal();
    const supported = await checkBiometricSupport();
    // Allow trying passkey login on secure, supported devices even if local hints were cleared.
    biometricsState.enabled = localEnabled || supported;
});

</script>
