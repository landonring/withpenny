<template>
    <section class="screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">Review receipt</p>
                <h1 class="screen-title">Here's what we found</h1>
            </div>
            <div class="accent-chip">Review</div>
        </div>

        <div v-if="receipt" class="card receipt-card">
            <img class="receipt-thumb" :src="receipt.image_url" alt="Receipt" />
            <div class="suggestions">
                <p class="card-sub">
                    {{ suggestionIntro }}
                </p>
                <div class="suggestion-row">
                    <span class="suggestion-label">Merchant</span>
                    <span class="suggestion-value">{{ suggestions.merchant || 'Not sure yet' }}</span>
                </div>
                <div class="suggestion-row">
                    <span class="suggestion-label">Total</span>
                    <span class="suggestion-value">
                        {{ suggestions.amount ? formatCurrency(suggestions.amount) : 'Not sure yet' }}
                    </span>
                </div>
                <div class="suggestion-row">
                    <span class="suggestion-label">Date</span>
                    <span class="suggestion-value">{{ suggestions.date || 'Not sure yet' }}</span>
                </div>
            </div>
        </div>
        <div v-else class="card muted">Loading receipt…</div>

        <div class="card">
            <p class="card-sub">You can adjust anything before saving. Nothing is saved until you confirm.</p>
            <TransactionForm
                v-if="formReady"
                :model-value="form"
                :error="error"
                :loading="saving"
                submit-label="Save spending"
                @submit="handleSubmit"
                @cancel="handleCancel"
            />
        </div>

        <div class="review-actions">
            <button class="ghost-button" type="button" @click="rescan">Re-scan</button>
            <button class="danger-button" type="button" :disabled="saving" @click="discard">
                Discard
            </button>
        </div>

        <p v-if="error" class="form-error">{{ error }}</p>
    </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import TransactionForm from '../components/TransactionForm.vue';
import { receiptState, fetchReceipt, discardReceipt } from '../stores/receipts';
import { addReceiptTransaction, initTransactions } from '../stores/transactions';
import { categoryLabels } from '../data/categories';

const route = useRoute();
const router = useRouter();

const saving = ref(false);
const error = ref('');
const formReady = ref(false);

const receipt = computed(() => receiptState.current);
const suggestions = computed(() => receiptState.suggestions || {});
const suggestionIntro = computed(() => {
    if (!receiptState.rawText) {
        return 'We could not read this clearly — you can still enter it manually.';
    }
    return 'Suggested details — adjust anything you want.';
});

const form = ref({
    amount: '',
    category: categoryLabels[0],
    note: '',
    transaction_date: new Date().toISOString().slice(0, 10),
});

const hydrateForm = () => {
    form.value = {
        amount: suggestions.value.amount ?? '',
        category: categoryLabels[0],
        note: '',
        transaction_date: suggestions.value.date ?? new Date().toISOString().slice(0, 10),
    };
    formReady.value = true;
};

onMounted(async () => {
    initTransactions();
    try {
        if (!receiptState.current || String(receiptState.current.id) !== String(route.params.id)) {
            await fetchReceipt(route.params.id);
        }
        hydrateForm();
    } catch (err) {
        error.value = 'We could not load this receipt. You can try scanning again.';
    }
});

const handleSubmit = async (payload) => {
    if (!receipt.value) return;
    saving.value = true;
    error.value = '';

    try {
        await addReceiptTransaction(receipt.value.id, payload);
        router.push({ name: 'home' });
    } catch (err) {
        if (err?.message === 'offline') {
            error.value = 'You are offline. Connect to save this receipt.';
        } else {
            error.value = err?.response?.data?.message || 'Unable to save right now.';
        }
    } finally {
        saving.value = false;
    }
};

const handleCancel = () => {
    router.back();
};

const discard = async () => {
    if (!receipt.value) return;
    await discardReceipt(receipt.value.id);
    router.push({ name: 'scan' });
};

const rescan = () => {
    router.push({ name: 'scan' });
};

const formatCurrency = (value) => {
    const amount = Number.parseFloat(value) || 0;
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 2,
    }).format(amount);
};
</script>
