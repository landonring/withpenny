<template>
    <section class="screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">Moments</p>
                <h1 class="screen-title">Everyday moments, in order</h1>
            </div>
            <div class="accent-chip">List</div>
        </div>

        <div class="card action-card">
            <div>
                <div class="card-title">Add spending or income</div>
                <p class="card-sub">Capture it while it is fresh.</p>
            </div>
            <router-link class="primary-button" :to="{ name: 'transactions-new' }">
                Add spending or income
            </router-link>
        </div>

        <div v-if="loading" class="card muted">Loading your moments…</div>
        <div v-else-if="!groupedEntries.length" class="card muted">
            No moments yet for this month.
        </div>

        <div v-else class="transaction-groups">
            <div v-for="group in groupedEntries" :key="group.date" class="card list-card">
                <div class="list-header">
                    <div class="card-title">{{ group.label }}</div>
                    <div class="muted">{{ group.items.length }} entries</div>
                </div>
                <div class="transaction-list">
                    <button
                        v-for="transaction in group.items"
                        :key="transaction.id"
                        :class="['transaction-row', { income: transaction.type === 'income' }]"
                        type="button"
                        @click="openTransaction(transaction.id)"
                    >
                        <div>
                            <div class="transaction-category">{{ transaction.category }}</div>
                            <div class="transaction-date">{{ formatDate(transaction.transaction_date) }}</div>
                        </div>
                        <div class="transaction-amount">{{ formatAmount(transaction) }}</div>
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { transactionsState, initTransactions } from '../stores/transactions';

const router = useRouter();

onMounted(() => {
    initTransactions();
});

const loading = computed(() => transactionsState.loading);

const groupedEntries = computed(() => {
    const groups = {};
    transactionsState.transactions.forEach((transaction) => {
        const dateKey = transaction.transaction_date;
        if (!groups[dateKey]) {
            groups[dateKey] = [];
        }
        groups[dateKey].push(transaction);
    });

    return Object.entries(groups)
        .map(([date, items]) => ({
            date,
            label: formatFullDate(date),
            items,
        }))
        .sort((a, b) => new Date(b.date) - new Date(a.date));
});

const openTransaction = (id) => {
    router.push({ name: 'transactions-edit', params: { id } });
};

const formatCurrency = (value) => {
    const amount = Number.parseFloat(value) || 0;
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 2,
    }).format(amount);
};

const formatAmount = (transaction) => {
    const base = formatCurrency(transaction.amount);
    if (transaction.type === 'income') {
        return `+${base}`;
    }
    return base;
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
    }).format(date);
};

const formatFullDate = (dateString) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('en-US', {
        weekday: 'short',
        month: 'long',
        day: 'numeric',
    }).format(date);
};
</script>
