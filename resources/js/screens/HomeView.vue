<template>
    <section class="screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">This month</p>
                <h1 class="screen-title">A calm look at your money, {{ displayName }}</h1>
            </div>
            <div class="accent-chip">Overview</div>
        </div>

        <div class="card month-switch">
            <button class="ghost-button" type="button" @click="shiftMonth(-1)">
                Prev
            </button>
            <div class="month-label">{{ monthLabel }}</div>
            <button class="ghost-button" type="button" :disabled="isCurrentMonth" @click="shiftMonth(1)">
                Next
            </button>
        </div>

        <div class="card overview-card">
            <div class="card-title">Monthly overview</div>
            <p class="card-sub">A simple snapshot of your flow.</p>

            <div class="overview-metrics">
                <div>
                    <div class="metric-label">Total spent</div>
                    <div class="metric-value">{{ formatCurrency(summary.spendingTotal) }}</div>
                </div>
                <div>
                    <div class="metric-label">Manual income</div>
                    <input
                        class="metric-input"
                        type="number"
                        inputmode="decimal"
                        min="0"
                        step="0.01"
                        :value="incomeDisplay"
                        @input="handleIncomeChange"
                        placeholder="0.00"
                    />
                </div>
            </div>

            <div class="overview-metrics secondary">
                <div>
                    <div class="metric-label">Money in</div>
                    <div class="metric-value">{{ formatCurrency(summary.moneyIn) }}</div>
                </div>
                <div>
                    <div class="metric-label">Net</div>
                    <div class="metric-value">{{ formatCurrency(summary.net) }}</div>
                </div>
            </div>

            <div class="chart-wrap">
                <div class="donut" :style="donutStyle">
                    <div class="donut-hole">
                        <span class="donut-label">Needs / Wants</span>
                    </div>
                </div>
                <div class="chart-legend">
                    <div class="legend-row">
                        <span class="legend-dot needs"></span>
                        <span>Needs</span>
                        <span class="legend-value">{{ formatCurrency(summary.breakdown.Needs) }}</span>
                    </div>
                    <div class="legend-row">
                        <span class="legend-dot wants"></span>
                        <span>Wants</span>
                        <span class="legend-value">{{ formatCurrency(summary.breakdown.Wants) }}</span>
                    </div>
                    <div class="legend-row">
                        <span class="legend-dot future"></span>
                        <span>Future</span>
                        <span class="legend-value">{{ formatCurrency(summary.breakdown.Future) }}</span>
                    </div>
                </div>
            </div>

            <div class="summary-text">
                <p class="muted" v-if="summary.count">
                    You've logged {{ summary.count }} transactions this month.
                </p>
                <p class="muted" v-else>
                    No spending moments logged yet this month.
                </p>
                <p class="muted" v-if="summary.topCategory">
                    Most spending this month was on {{ summary.topCategory }}.
                </p>
            </div>
        </div>

        <div class="card action-card">
            <div>
                <div class="card-title">Add a spending moment</div>
                <p class="card-sub">Quick notes now, details later.</p>
            </div>
            <router-link class="primary-button" :to="{ name: 'transactions-new' }">
                Add spending
            </router-link>
        </div>

        <div class="card savings-nudge">
            <div>
                <div class="card-title">You're building toward something</div>
                <p class="card-sub">If it feels right, set a little aside.</p>
            </div>
            <router-link class="ghost-button" :to="{ name: 'savings' }">
                Set a little aside
            </router-link>
        </div>

        <div class="card list-card">
            <div class="list-header">
                <div>
                    <div class="card-title">Recent spending</div>
                    <p class="card-sub">Most recent entries in this month.</p>
                </div>
                <router-link class="ghost-button" :to="{ name: 'transactions' }">
                    See all
                </router-link>
            </div>

            <div v-if="loading" class="muted">Loading your spending…</div>
            <div v-else-if="!recentTransactions.length" class="muted">
                Add your first spending moment to see it here.
            </div>
            <div v-else class="transaction-list">
                <button
                    v-for="transaction in recentTransactions"
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

        <div v-if="showBiometricPrompt" class="card bio-card">
            <div>
                <div class="card-title">Use Face ID next time?</div>
                <p class="card-sub">It’s a quick way to return, whenever you want.</p>
            </div>
            <div class="journey-actions">
                <button class="primary-button" type="button" :disabled="biometricBusy" @click="enableBiometricPrompt">
                    {{ biometricBusy ? 'Enabling…' : 'Enable Face ID' }}
                </button>
                <button class="ghost-button" type="button" @click="dismissBiometricPrompt">
                    Not now
                </button>
            </div>
            <p v-if="biometricMessage" class="muted">{{ biometricMessage }}</p>
        </div>

        <div class="card account-card">
            <div>
                <div class="card-title">Profile settings</div>
                <p class="card-sub">Update your email, password, or sign out.</p>
            </div>
            <router-link class="ghost-button" :to="{ name: 'profile' }">
                Open profile
            </router-link>
        </div>

        <div class="card account-card">
            <div>
                <div class="card-title">Bank statements</div>
                <p class="card-sub">Upload a statement if you want to save time.</p>
            </div>
            <router-link class="ghost-button" :to="{ name: 'statements' }">
                Upload
            </router-link>
        </div>
    </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { authState } from '../stores/auth';
import {
    biometricsState,
    checkBiometricSupport,
    enableBiometrics,
    isBiometricDismissed,
    markBiometricDismissed,
    refreshBiometricStatus,
} from '../stores/biometrics';
import {
    transactionsState,
    initTransactions,
    setMonth,
    updateIncome,
    getMonthKey,
} from '../stores/transactions';

const router = useRouter();

onMounted(() => {
    initTransactions();
    initBiometrics();
});

const displayName = computed(() => {
    const name = authState.user?.name || '';
    return name.split(' ')[0] || 'there';
});

const loading = computed(() => transactionsState.loading);
const summary = computed(() => transactionsState.summary);
const recentTransactions = computed(() => transactionsState.transactions.slice(0, 5));
const biometricMessage = ref('');
const biometricBusy = ref(false);
const showBiometricPrompt = ref(false);

const initBiometrics = async () => {
    await checkBiometricSupport();
    if (!biometricsState.supported) {
        showBiometricPrompt.value = false;
        return;
    }

    await refreshBiometricStatus();

    showBiometricPrompt.value = authState.justLoggedIn && !isBiometricDismissed() && !biometricsState.enabled;
    if (!showBiometricPrompt.value) {
        authState.justLoggedIn = false;
    }
};

const enableBiometricPrompt = async () => {
    biometricBusy.value = true;
    biometricMessage.value = '';

    try {
        await enableBiometrics();
        biometricMessage.value = 'Face ID is ready for next time.';
        showBiometricPrompt.value = false;
        authState.justLoggedIn = false;
    } catch (err) {
        biometricMessage.value = err?.response?.data?.message || err?.message || 'Unable to enable Face ID right now.';
    } finally {
        biometricBusy.value = false;
    }
};

const dismissBiometricPrompt = () => {
    markBiometricDismissed();
    authState.justLoggedIn = false;
    showBiometricPrompt.value = false;
};

const monthLabel = computed(() => {
    const [year, month] = transactionsState.monthKey.split('-');
    const date = new Date(Number.parseInt(year, 10), Number.parseInt(month, 10) - 1, 1);
    return new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' }).format(date);
});

const isCurrentMonth = computed(() => {
    const current = getMonthKey(new Date());
    return current === transactionsState.monthKey;
});

const incomeDisplay = computed(() => (transactionsState.income ? transactionsState.income : ''));

const donutStyle = computed(() => {
    const total = summary.value.total || 1;
    const needsPercent = (summary.value.breakdown.Needs / total) * 100;
    const wantsPercent = (summary.value.breakdown.Wants / total) * 100;
    const futurePercent = (summary.value.breakdown.Future / total) * 100;

    return {
        background: `conic-gradient(
            #c6d2c4 0% ${needsPercent}%,
            #e9dccf ${needsPercent}% ${needsPercent + wantsPercent}%,
            #d7bfa9 ${needsPercent + wantsPercent}% ${needsPercent + wantsPercent + futurePercent}%
        )`,
    };
});

const shiftMonth = (delta) => {
    const [year, month] = transactionsState.monthKey.split('-').map(Number);
    const date = new Date(year, month - 1 + delta, 1);
    setMonth(getMonthKey(date));
};

const handleIncomeChange = (event) => {
    updateIncome(event.target.value);
};

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
</script>
