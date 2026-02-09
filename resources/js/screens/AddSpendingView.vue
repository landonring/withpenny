<template>
    <section class="screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">Add spending</p>
                <h1 class="screen-title">Capture a spending moment</h1>
            </div>
            <div class="accent-chip">New</div>
        </div>

        <div class="card">
            <TransactionForm
                :error="error"
                :loading="loading"
                submit-label="Save spending"
                @submit="handleSubmit"
                @cancel="handleCancel"
            />
        </div>
    </section>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import TransactionForm from '../components/TransactionForm.vue';
import { addTransaction } from '../stores/transactions';

const router = useRouter();
const loading = ref(false);
const error = ref('');

const handleSubmit = async (payload) => {
    loading.value = true;
    error.value = '';

    try {
        await addTransaction(payload);
        router.push({ name: 'home' });
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to save right now.';
    } finally {
        loading.value = false;
    }
};

const handleCancel = () => {
    router.back();
};
</script>
