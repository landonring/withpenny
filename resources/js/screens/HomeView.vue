<template>
    <section class="screen">
        <div class="screen-header" data-onboarding="dashboard">
            <div>
                <p class="eyebrow">This month</p>
                <h1 class="screen-title">A calm look at your money, {{ displayName }}</h1>
            </div>
            <div class="accent-chip">Overview</div>
        </div>

        <div class="card month-switch" data-onboarding="home-month-selector">
            <button class="home-button" type="button" @click="shiftMonth(-1)">
                Prev
            </button>
            <div class="month-label">{{ monthLabel }}</div>
            <button class="home-button" type="button" :disabled="isCurrentMonth" @click="shiftMonth(1)">
                Next
            </button>
        </div>

        <div class="card overview-card">
            <div class="card-title">Monthly overview</div>
            <p class="card-sub">A simple snapshot of your flow.</p>

            <div class="overview-focus">
                <div class="overview-summary-target" data-onboarding="home-summary">
                    <div class="balance-block">
                        <div class="balance-label">Current balance</div>
                        <div class="balance-value">{{ formatCurrency(summary.net) }}</div>
                    </div>
                    <div class="overview-stats">
                        <div class="stat-item">
                            <div class="metric-label">Money in</div>
                            <div class="metric-value">{{ formatCurrency(summary.moneyIn) }}</div>
                        </div>
                        <div class="stat-item">
                            <div class="metric-label">Total spent</div>
                            <div class="metric-value">{{ formatCurrency(summary.spendingTotal) }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="chart-wrap">
                <div class="donut" data-onboarding="home-donut" :style="donutStyle">
                    <div class="donut-hole">
                        <span class="donut-label">Needs / Wants / Future</span>
                    </div>
                </div>
                <div class="chart-breakdown-target" data-onboarding="home-breakdown">
                    <div class="chart-legend">
                        <div class="legend-row">
                            <span class="legend-dot needs"></span>
                            <span>Needs</span>
                            <span class="legend-value">
                                {{ formatCurrency(summary.breakdown.Needs) }} · {{ formatPercent(percentages.needs) }}
                            </span>
                        </div>
                        <div class="legend-row">
                            <span class="legend-dot wants"></span>
                            <span>Wants</span>
                            <span class="legend-value">
                                {{ formatCurrency(summary.breakdown.Wants) }} · {{ formatPercent(percentages.wants) }}
                            </span>
                        </div>
                        <div class="legend-row">
                            <span class="legend-dot future"></span>
                            <span>Future</span>
                            <span class="legend-value">
                                {{ formatCurrency(summary.breakdown.Future) }} · {{ formatPercent(percentages.future) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="summary-text">
                <p class="muted" v-if="summary.topCategory">
                    Your spending this month was mostly on {{ summary.topCategory }}.
                </p>
            </div>
        </div>

        <div class="home-card-grid">
            <div class="card action-card">
                <div>
                    <div class="card-title">Add a spending or income moment</div>
                    <p class="card-sub">Quick notes now, details later.</p>
                </div>
                <router-link class="home-button" :to="{ name: 'transactions-new' }">
                    Add spending or income
                </router-link>
            </div>

            <div class="card savings-nudge">
                <div>
                    <div class="card-title">You're building toward something</div>
                    <p class="card-sub">If it feels right, set a little aside.</p>
                </div>
                <router-link class="home-button" :to="{ name: 'savings' }">
                    Set a little aside
                </router-link>
            </div>

            <div class="card account-card statement-card">
                <div>
                    <div class="card-title">Bank statements</div>
                    <p class="card-sub">Upload a statement and let Penny pull out the transactions.</p>
                </div>
                <router-link class="home-button" :to="{ name: 'statements-scan' }">
                    Upload statement
                </router-link>
            </div>

            <div class="card list-card">
                <div class="list-header">
                    <div>
                        <div class="card-title">Recent spending</div>
                        <p class="card-sub">Most recent entries in this month.</p>
                    </div>
                    <router-link class="home-button" :to="{ name: 'transactions' }">
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

        <div v-if="duplicateGroups.length" class="card list-card grid-full">
            <div class="list-header">
                <div>
                    <div class="card-title">Duplicates</div>
                    <p class="card-sub">Exact matches found this month.</p>
                </div>
            </div>

            <div class="transaction-list">
                <div v-for="duplicate in duplicateGroups" :key="duplicate.id" class="duplicate-row">
                    <button
                        :class="['transaction-row', { income: duplicate.transaction.type === 'income' }]"
                        type="button"
                        @click="openTransaction(duplicate.transaction.id)"
                    >
                        <div>
                            <div class="transaction-category">{{ duplicate.transaction.category }}</div>
                            <div class="transaction-date">{{ formatDate(duplicate.transaction.transaction_date) }}</div>
                        </div>
                        <div class="transaction-amount">
                            {{ formatAmount(duplicate.transaction) }}
                            <span class="duplicate-count">{{ duplicate.count }}×</span>
                            <span v-if="!duplicate.exact" class="duplicate-possible">Possible</span>
                        </div>
                    </button>
                    <button
                        v-if="duplicate.exact"
                        class="ghost-button duplicate-action"
                        type="button"
                        @click="removeDuplicateGroup(duplicate)"
                    >
                        Remove duplicates
                    </button>
                </div>
            </div>
        </div>

        <div v-if="showBiometricPrompt" class="card bio-card grid-full">
            <div>
                <div class="card-title">Use a passkey next time?</div>
                <p class="card-sub">It’s a quick way to return, whenever you want.</p>
            </div>
            <div class="journey-actions">
                <button class="home-button" type="button" :disabled="biometricBusy" @click="enableBiometricPrompt">
                    {{ biometricBusy ? 'Enabling…' : 'Add passkey' }}
                </button>
                <button class="home-button" type="button" @click="dismissBiometricPrompt">
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
            <router-link class="home-button" :to="{ name: 'profile' }">
                Open profile
            </router-link>
        </div>

        </div>
    </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { authState } from '../stores/auth';
import { ensureUsageStatus, usageState } from '../stores/usage';
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
    getMonthKey,
    deleteTransaction,
} from '../stores/transactions';

const router = useRouter();
onMounted(() => {
    initTransactions();
    initBiometrics();
    ensureUsageStatus();
});

const displayName = computed(() => {
    const name = authState.user?.name || '';
    return name.split(' ')[0] || 'there';
});

const loading = computed(() => transactionsState.loading);
const summary = computed(() => transactionsState.summary);
const recentTransactions = computed(() => transactionsState.transactions);
const duplicateGroups = computed(() => {
    const groups = new Map();
    const transactions = transactionsState.transactions || [];

    transactions.forEach((transaction) => {
        const amount = Number.parseFloat(transaction.amount || 0).toFixed(2);
        const key = [
            transaction.type || 'spending',
            amount,
            transaction.transaction_date || '',
        ].join('|');

        if (!groups.has(key)) {
            groups.set(key, []);
        }
        groups.get(key).push(transaction);
    });

    return Array.from(groups.values())
        .filter((group) => group.length > 1)
        .sort((a, b) => new Date(b[0].transaction_date) - new Date(a[0].transaction_date))
        .map((group) => {
            const sorted = [...group].sort((a, b) => new Date(b.transaction_date) - new Date(a.transaction_date));
            const categories = new Set(group.map((item) => item.category));
            const notes = new Set(group.map((item) => (item.note || '').trim().toLowerCase()));
            const exact = categories.size === 1 && notes.size <= 1;
            return {
                id: `${group[0].id || 'duplicate'}-${group.length}`,
                transaction: sorted[0],
                count: group.length,
                exact,
                ids: sorted.map((item) => item.id),
            };
        });
});
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
        biometricMessage.value = 'Passkey is ready for next time.';
        showBiometricPrompt.value = false;
        authState.justLoggedIn = false;
    } catch (err) {
        biometricMessage.value = err?.response?.data?.message || err?.message || 'Unable to add a passkey right now.';
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

const donutColors = ['#c6d2c4', '#e9dccf', '#d7bfa9'];
const donutMinZeroSlicePercent = 2;

const donutStyle = computed(() => {
    const rawSlices = [
        Number(summary.value.breakdown.Needs || 0),
        Number(summary.value.breakdown.Wants || 0),
        Number(summary.value.breakdown.Future || 0),
    ];
    const total = rawSlices.reduce((acc, value) => acc + value, 0);

    if (total <= 0) {
        return {
            background: `conic-gradient(
                ${donutColors[0]} 0% 33.3333%,
                ${donutColors[1]} 33.3333% 66.6666%,
                ${donutColors[2]} 66.6666% 100%
            )`,
        };
    }

    const zeroCount = rawSlices.filter((value) => value <= 0).length;
    const reserveForZeros = zeroCount * donutMinZeroSlicePercent;
    const distributable = Math.max(0, 100 - reserveForZeros);
    const nonZeroTotal = rawSlices.reduce((acc, value) => (value > 0 ? acc + value : acc), 0);
    const slices = rawSlices.map((value) => {
        if (value <= 0) return donutMinZeroSlicePercent;
        if (nonZeroTotal <= 0) return 0;
        return (value / nonZeroTotal) * distributable;
    });

    const needsEnd = slices[0];
    const wantsEnd = needsEnd + slices[1];

    return {
        background: `conic-gradient(
            ${donutColors[0]} 0% ${needsEnd}%,
            ${donutColors[1]} ${needsEnd}% ${wantsEnd}%,
            ${donutColors[2]} ${wantsEnd}% 100%
        )`,
    };
});

const percentages = computed(() => {
    const total = summary.value.total || 1;
    return {
        needs: (summary.value.breakdown.Needs / total) * 100,
        wants: (summary.value.breakdown.Wants / total) * 100,
        future: (summary.value.breakdown.Future / total) * 100,
    };
});

const shiftMonth = (delta) => {
    const [year, month] = transactionsState.monthKey.split('-').map(Number);
    const date = new Date(year, month - 1 + delta, 1);
    setMonth(getMonthKey(date));
};

const openTransaction = (id) => {
    router.push({ name: 'transactions-edit', params: { id } });
};

const removeDuplicateGroup = async (group) => {
    const ids = group?.ids || [];
    if (ids.length < 2) return;
    const count = ids.length - 1;
    const label = count === 1 ? 'duplicate' : 'duplicates';
    if (!window.confirm(`Remove ${count} ${label} and keep one?`)) {
        return;
    }
    for (const id of ids.slice(1)) {
        await deleteTransaction(id);
    }
};

const formatCurrency = (value) => {
    const amount = Number.parseFloat(value) || 0;
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 2,
    }).format(amount);
};

const formatPercent = (value) => {
    const amount = Number.isFinite(value) ? value : 0;
    return `${amount.toFixed(0)}%`;
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
