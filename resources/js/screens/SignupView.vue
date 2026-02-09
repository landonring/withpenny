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

                <p v-if="error" class="form-error">{{ error }}</p>

                <button class="primary-button" type="submit" :disabled="loading">
                    {{ loading ? 'Creating account…' : 'Sign up' }}
                </button>
            </form>

            <p class="muted">
                Already have an account?
                <router-link to="/login">Log in</router-link>
            </p>
        </div>
    </section>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { register } from '../stores/auth';

const router = useRouter();

const form = ref({
    name: '',
    email: '',
    password: '',
});

const error = ref('');
const loading = ref(false);
const showPassword = ref(false);

const handleSubmit = async () => {
    error.value = '';
    loading.value = true;

    try {
        await register(form.value);
        router.push('/');
    } catch (err) {
        const message = err?.response?.data?.message;
        const errors = err?.response?.data?.errors;
        error.value = message || (errors ? Object.values(errors).flat().join(' ') : 'Unable to sign up right now.');
    } finally {
        loading.value = false;
    }
};
</script>
