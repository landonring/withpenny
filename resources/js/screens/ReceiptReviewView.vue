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
            <div class="suggestions">
                <p class="card-sub">
                    {{ suggestionIntro }}
                </p>
                <p class="ai-disclaimer">Penny AI can make mistakes. Check important info.</p>
                <div class="suggestion-row">
                    <span class="suggestion-label">Merchant</span>
                    <span class="suggestion-value">{{ suggestions.merchant || 'Not sure yet' }}</span>
                </div>
                <div class="suggestion-row">
                    <span class="suggestion-label">Total</span>
                    <span class="suggestion-value">
                        {{ displayTotal }}
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
            <p v-if="isProcessing" class="muted">Processing your receipt…</p>
            <p v-if="processingError" class="form-error">{{ processingError }}</p>
            <p v-if="warningsText && !isProcessing" class="muted">{{ warningsText }}</p>
            <div v-if="lineItems.length" class="line-items-card">
                <p class="card-sub">Each line below will save as its own transaction. Edit anything you need.</p>
                <div class="line-items">
                    <div v-for="(item, index) in lineItems" :key="item.id" class="line-item">
                        <label class="field">
                            <span>Item</span>
                            <input v-model="item.note" type="text" placeholder="Item name" />
                        </label>
                        <label class="field">
                            <span>
                                Amount
                                <span v-if="item.estimated" class="estimated-badge">Estimated</span>
                            </span>
                            <input
                                v-model="item.amount"
                                type="number"
                                inputmode="decimal"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
                            />
                        </label>
                        <label class="field">
                            <span>Category</span>
                            <select v-model="item.category">
                                <option v-for="category in categoryLabels" :key="category" :value="category">
                                    {{ category }}
                                </option>
                            </select>
                        </label>
                        <button class="ghost-button line-item-remove" type="button" @click="removeLineItem(index)">
                            Remove
                        </button>
                    </div>
                </div>
                <button class="ghost-button" type="button" @click="addLineItem">Add item</button>
            </div>

            <TransactionForm
                v-else-if="formReady"
                :model-value="form"
                :error="error"
                :loading="saving || isProcessing"
                submit-label="Save spending"
                @submit="handleSubmit"
                @cancel="handleCancel"
            />

            <div v-if="lineItems.length" class="line-items-actions">
                <label class="field">
                    <span>Date</span>
                    <input v-model="lineDate" type="date" />
                </label>
                <p class="muted">Total: {{ formatCurrency(lineItemsTotal) }}</p>
                <div class="form-actions">
                    <button class="ghost-button" type="button" @click="handleCancel">
                        Cancel
                    </button>
                    <button class="primary-button" type="button" :disabled="saving || isProcessing" @click="handleSubmitItems">
                        {{ saving ? 'Saving…' : (isProcessing ? 'Processing…' : `Save ${lineItems.length} items`) }}
                    </button>
                </div>
            </div>
        </div>

        <div class="review-actions">
            <button class="ghost-button" type="button" @click="rescan">Re-scan</button>
            <button class="danger-button" type="button" :disabled="saving || isProcessing" @click="discard">
                Discard
            </button>
        </div>

        <p v-if="error" class="form-error">{{ error }}</p>
    </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import TransactionForm from '../components/TransactionForm.vue';
import { receiptState, fetchReceipt, discardReceipt } from '../stores/receipts';
import { addReceiptTransaction, addReceiptTransactions, initTransactions } from '../stores/transactions';
import { categoryLabels } from '../data/categories';

const route = useRoute();
const router = useRouter();

const saving = ref(false);
const error = ref('');
const formReady = ref(false);
const processingError = ref('');
const pollAttempts = ref(0);
const maxPollAttempts = 40;
const processingTimedOut = ref(false);
let pollTimer = null;

const receipt = computed(() => receiptState.current);
const suggestions = computed(() => receiptState.suggestions || {});
const isProcessing = computed(() => {
    const status = String(receipt.value?.processing_status || '');
    return !processingTimedOut.value && (status === 'queued' || status === 'processing');
});
const lineItems = ref([]);
const lineDate = ref(new Date().toISOString().slice(0, 10));
const lineItemsTotal = computed(() => {
    return lineItems.value.reduce((sum, item) => sum + (Number.parseFloat(item.amount) || 0), 0);
});
const displayTotal = computed(() => {
    if (lineItems.value.length) {
        return formatCurrency(lineItemsTotal.value);
    }
    return suggestions.value.amount ? formatCurrency(suggestions.value.amount) : 'Not sure yet';
});
const suggestionIntro = computed(() => {
    if (isProcessing.value) {
        return 'Penny is still reading this receipt. You can review once extraction finishes.';
    }
    if (!receiptState.rawText) {
        return 'We could not read this clearly — you can still enter it manually.';
    }
    return 'Suggested details — adjust anything you want.';
});
const warningsText = computed(() => {
    const warnings = Array.isArray(receiptState.warnings) ? receiptState.warnings : [];
    return warnings.join(' ');
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

const hydrateLineItems = () => {
    if (receiptState.lineItems?.length) {
        lineItems.value = receiptState.lineItems.map((item, index) => ({
            id: `${receiptState.current?.id || 'receipt'}-${index}`,
            note: item.description || '',
            amount: item.amount ?? '',
            category: categoryLabels[0],
            estimated: !!item.estimated,
        }));
    } else {
        lineItems.value = [];
    }
    lineDate.value = suggestions.value.date ?? new Date().toISOString().slice(0, 10);
};

const refreshReceipt = async () => {
    await fetchReceipt(route.params.id);
    processingError.value = String(receiptState.current?.processing_error || '');
    hydrateForm();
    hydrateLineItems();

    const status = String(receipt.value?.processing_status || '');
    if (status !== 'queued' && status !== 'processing') {
        processingTimedOut.value = false;
    }

    if (isProcessing.value) {
        pollAttempts.value += 1;
        if (pollAttempts.value >= maxPollAttempts) {
            processingError.value = processingError.value || 'Processing is taking longer than expected. Please try scanning this receipt again.';
            processingTimedOut.value = true;
            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }
            return;
        }
        if (pollTimer) clearTimeout(pollTimer);
        pollTimer = setTimeout(() => {
            refreshReceipt();
        }, 1800);
    } else if (pollTimer) {
        clearTimeout(pollTimer);
        pollTimer = null;
        pollAttempts.value = 0;
    } else {
        pollAttempts.value = 0;
    }
};

onMounted(async () => {
    initTransactions();
    try {
        await refreshReceipt();
    } catch (err) {
        error.value = 'We could not load this receipt. You can try scanning again.';
    }
});

onBeforeUnmount(() => {
    if (pollTimer) {
        clearTimeout(pollTimer);
    }
});

const handleSubmit = async (payload) => {
    if (!receipt.value || isProcessing.value) return;
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

const handleSubmitItems = async () => {
    if (!receipt.value || !lineItems.value.length || isProcessing.value) return;
    saving.value = true;
    error.value = '';

    try {
        const items = lineItems.value
            .map((item) => ({
                amount: Number.parseFloat(item.amount),
                note: item.note?.trim() || null,
                category: item.category || categoryLabels[0],
            }))
            .filter((item) => Number.isFinite(item.amount) && item.amount > 0);

        if (items.length !== lineItems.value.length) {
            error.value = 'Add an amount for each item before saving.';
            saving.value = false;
            return;
        }

        if (!items.length) {
            error.value = 'Add at least one item with an amount.';
            saving.value = false;
            return;
        }

        await addReceiptTransactions(receipt.value.id, {
            items,
            transaction_date: lineDate.value,
        });
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
    if (!receipt.value || isProcessing.value) return;
    await discardReceipt(receipt.value.id);
    router.push({ name: 'scan' });
};

const rescan = () => {
    router.push({ name: 'scan' });
};

const addLineItem = () => {
    lineItems.value = [
        ...lineItems.value,
        { id: `${Date.now()}-${Math.random()}`, note: '', amount: '', category: categoryLabels[0], estimated: false },
    ];
};

const removeLineItem = (index) => {
    lineItems.value = lineItems.value.filter((_, i) => i !== index);
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
