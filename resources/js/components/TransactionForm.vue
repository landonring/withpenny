<template>
    <form class="transaction-form" @submit.prevent="handleSubmit">
        <div class="field">
            <span>Type</span>
            <div class="type-toggle">
                <button
                    type="button"
                    :class="['toggle-button', { active: form.type === 'spending' }]"
                    @click="setType('spending')"
                >
                    Spending
                </button>
                <button
                    type="button"
                    :class="['toggle-button', { active: form.type === 'income' }]"
                    @click="setType('income')"
                >
                    Income
                </button>
            </div>
        </div>

        <label class="field">
            <span>Amount</span>
            <input
                v-model="form.amount"
                type="number"
                inputmode="decimal"
                min="0"
                step="0.01"
                required
                placeholder="0.00"
            />
        </label>

        <label v-if="form.type === 'spending'" class="field">
            <span>Category</span>
            <select v-model="form.category" required>
                <option value="" disabled>Select a category</option>
                <option v-for="category in categoryLabels" :key="category" :value="category">
                    {{ category }}
                </option>
            </select>
        </label>

        <label v-else class="field">
            <span>Category</span>
            <input v-model="form.category" type="text" disabled />
        </label>

        <label class="field">
            <span>Note (optional)</span>
            <input v-model="form.note" type="text" placeholder="Add a gentle note" />
        </label>

        <label class="field">
            <span>Date</span>
            <input v-model="form.transaction_date" type="date" />
        </label>

        <p v-if="error" class="form-error">{{ error }}</p>

        <div class="form-actions">
            <button class="ghost-button" type="button" @click="handleCancel">
                Cancel
            </button>
            <button class="primary-button" type="submit" :disabled="loading">
                {{ loading ? 'Saving…' : submitLabel }}
            </button>
        </div>
    </form>
</template>

<script setup>
import { reactive, watch } from 'vue';
import { categoryLabels } from '../data/categories';

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({}),
    },
    submitLabel: {
        type: String,
        default: 'Save',
    },
    loading: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['submit', 'cancel']);

const today = new Date().toISOString().slice(0, 10);

const form = reactive({
    type: 'spending',
    amount: '',
    category: '',
    note: '',
    transaction_date: today,
});

watch(
    () => props.modelValue,
    (value) => {
        form.type = value?.type ?? 'spending';
        form.amount = value?.amount ?? '';
        form.category = value?.category ?? '';
        if (form.type === 'income' && !form.category) {
            form.category = 'Income';
        }
        form.note = value?.note ?? '';
        form.transaction_date = value?.transaction_date ?? today;
    },
    { immediate: true }
);

const setType = (nextType) => {
    form.type = nextType;
    if (nextType === 'income') {
        form.category = 'Income';
    } else if (form.category === 'Income') {
        form.category = 'Misc';
    }
};

const handleSubmit = () => {
    emit('submit', {
        type: form.type,
        amount: form.amount,
        category: form.category,
        note: form.note || null,
        transaction_date: form.transaction_date,
    });
};

const handleCancel = () => emit('cancel');
</script>
