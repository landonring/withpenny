<template>
    <section class="screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">{{ transactionTypeLabel }}</p>
                <h1 class="screen-title">Adjust with ease</h1>
            </div>
            <div class="accent-chip">Edit</div>
        </div>

        <div class="card">
            <TransactionForm
                v-if="transaction"
                :model-value="transaction"
                :error="error"
                :loading="loading"
                :submit-label="submitLabel"
                @submit="handleSubmit"
                @cancel="handleCancel"
            />
            <div v-else class="muted">Loading transaction…</div>
        </div>

        <button class="danger-button" type="button" :disabled="loading" @click="handleDelete">
            {{ deleteLabel }}
        </button>
    </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import TransactionForm from '../components/TransactionForm.vue';
import {
    deleteTransaction,
    getTransactionById,
    initTransactions,
    updateTransaction,
} from '../stores/transactions';

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const error = ref('');

onMounted(() => {
    initTransactions();
});

const transactionId = computed(() => route.params.id);
const transaction = computed(() => getTransactionById(transactionId.value));
const isIncome = computed(() => transaction.value?.type === 'income');
const transactionTypeLabel = computed(() => (isIncome.value ? 'Edit income' : 'Edit spending'));
const submitLabel = computed(() => (isIncome.value ? 'Update income' : 'Update spending'));
const deleteLabel = computed(() => (isIncome.value ? 'Delete income' : 'Delete spending'));

const handleSubmit = async (payload) => {
    if (!transaction.value) return;
    loading.value = true;
    error.value = '';

    try {
        await updateTransaction(transaction.value.id, payload);
        router.push({ name: 'transactions' });
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to update right now.';
    } finally {
        loading.value = false;
    }
};

const handleDelete = async () => {
    if (!transaction.value) return;
    loading.value = true;

    try {
        await deleteTransaction(transaction.value.id);
        router.push({ name: 'transactions' });
    } finally {
        loading.value = false;
    }
};

const handleCancel = () => {
    router.back();
};
</script>
