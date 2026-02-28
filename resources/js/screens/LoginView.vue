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
