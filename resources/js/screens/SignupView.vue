<template>
    <section class="screen auth-screen">
        <div class="auth-card">
            <div>
                <p class="eyebrow">Get started</p>
                <h1 class="screen-title">Create your Penny account</h1>
                <p class="card-sub">No pressure. Just a calm starting point.</p>
            </div>

            <form class="auth-form" @submit.prevent="handleSubmit">
                <label class="field">
                    <span>Name</span>
                    <input v-model="form.name" type="text" autocomplete="name" required />
                </label>

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
                            autocomplete="new-password"
                            minlength="8"
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

                <label class="field">
                    <span>Confirm password</span>
                    <div class="field-row">
                        <input
                            v-model="form.password_confirmation"
                            :type="showConfirmPassword ? 'text' : 'password'"
                            autocomplete="new-password"
                            minlength="8"
                            required
                        />
                        <button
                            class="field-toggle field-toggle-eye"
                            type="button"
                            :aria-label="showConfirmPassword ? 'Hide password confirmation' : 'Show password confirmation'"
                            @click="showConfirmPassword = !showConfirmPassword"
                        >
                            <svg v-if="!showConfirmPassword" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 5C6.6 5 2.2 8.4 1 12c1.2 3.6 5.6 7 11 7s9.8-3.4 11-7c-1.2-3.6-5.6-7-11-7zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8z"/>
                                <circle cx="12" cy="12" r="2.2"/>
                            </svg>
                            <svg v-else viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M3.3 2 2 3.3l3 3C3.2 7.7 1.8 9.7 1 12c1.2 3.6 5.6 7 11 7 2.1 0 4-.5 5.6-1.4l3.1 3.1 1.3-1.3L3.3 2zm8.7 14a4 4 0 0 1-4-4c0-.6.1-1.1.3-1.6l5.3 5.3c-.5.2-1 .3-1.6.3zM12 5c5.4 0 9.8 3.4 11 7-.5 1.6-1.6 3-3 4.2l-1.4-1.4c.8-.8 1.5-1.8 1.9-2.8-1.2-3-4.8-5.4-8.5-5.4-1.3 0-2.5.3-3.6.8L7.1 6C8.6 5.4 10.2 5 12 5z"/>
                            </svg>
                        </button>
                    </div>
                </label>

                <label class="checkbox-field">
                    <input v-model="form.age_confirmed" type="checkbox" required />
                    <span>I confirm I am at least 15 years old.</span>
                </label>

                <p v-if="error" class="form-error">{{ error }}</p>

                <button class="primary-button" type="submit" :disabled="loading">
                    {{ loading ? 'Creating account…' : 'Sign up' }}
                </button>
            </form>

            <p class="muted">
                Already have an account?
                <router-link :to="{ name: 'login', query: billingQuery }">Log in</router-link>
            </p>
        </div>
    </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { register } from '../stores/auth';
import { startCheckout } from '../stores/billing';

const router = useRouter();
const route = useRoute();

const form = ref({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    age_confirmed: false,
});

const error = ref('');
const loading = ref(false);
const showPassword = ref(false);
const showConfirmPassword = ref(false);
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

const handleSubmit = async () => {
    error.value = '';
    loading.value = true;

    if (form.value.password !== form.value.password_confirmation) {
        error.value = 'Passwords must match.';
        loading.value = false;
        return;
    }

    try {
        await register(form.value);
        const intent = getPlanIntent();
        if (intent) {
            const data = await startCheckout(intent.plan, intent.interval);
            if (data?.url) {
                window.location.href = data.url;
                return;
            }
        }
        const redirect = route.query.redirect || '/app';
        router.push(redirect);
    } catch (err) {
        const message = err?.response?.data?.message;
        const errors = err?.response?.data?.errors;
        error.value = message || (errors ? Object.values(errors).flat().join(' ') : 'Unable to sign up right now.');
    } finally {
        loading.value = false;
    }
};
</script>
