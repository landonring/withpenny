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
                :loading="loading || loadingData"
                :submit-label="submitLabel"
                @submit="handleSubmit"
                @cancel="handleCancel"
            />
            <div v-else class="muted">{{ error || 'Loading transaction…' }}</div>
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
    fetchTransactionById,
    getTransactionById,
    initTransactions,
    updateTransaction,
} from '../stores/transactions';

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const loadingData = ref(false);
const error = ref('');

const loadTransaction = async () => {
    loadingData.value = true;
    error.value = '';
    try {
        await initTransactions();
        if (!transaction.value) {
            await fetchTransactionById(transactionId.value);
        }
        if (!transaction.value) {
            error.value = 'Transaction not found.';
        }
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to load this transaction.';
    } finally {
        loadingData.value = false;
    }
};

onMounted(() => {
    loadTransaction();
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
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to delete right now.';
    } finally {
        loading.value = false;
    }
};

const handleCancel = () => {
    router.back();
};
</script>
