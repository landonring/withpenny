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
                        <button class="field-toggle" type="button" @click="showPassword = !showPassword">
                            {{ showPassword ? 'Hide' : 'Show' }}
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
    age_confirmed: false,
});

const error = ref('');
const loading = ref(false);
const showPassword = ref(false);
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
