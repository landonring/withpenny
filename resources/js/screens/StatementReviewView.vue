<template>
    <section class="screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">Review</p>
                <h1 class="screen-title">Check what to add</h1>
            </div>
            <div class="accent-chip">Import</div>
        </div>

        <div class="card">
            <p class="card-sub">
                Penny made a first pass. You're always in control.
            </p>
            <p class="muted">
                Nothing is added until you confirm. You can edit or remove any item.
            </p>
            <p v-if="showNoIncomeNote" class="muted">
                No income entries were found in this statement. That's okay.
            </p>
        </div>

        <div v-if="summaryAvailable" class="card data-summary">
            <div class="card-title">Statement summary</div>
            <div v-if="summary?.opening_balance != null" class="detail-row">
                <span>Opening balance</span>
                <span>{{ formatCurrency(summary.opening_balance) }}</span>
            </div>
            <div v-if="summary?.closing_balance != null" class="detail-row">
                <span>Closing balance</span>
                <span>{{ formatCurrency(summary.closing_balance) }}</span>
            </div>
            <div v-if="statementIncome != null" class="detail-row">
                <span>Total income (statement)</span>
                <span>{{ formatCurrency(statementIncome) }}</span>
            </div>
            <div class="detail-row">
                <span>Total spending (entries)</span>
                <span>{{ formatCurrency(totals.spending) }}</span>
            </div>
        </div>

        <div v-if="loading" class="muted">Preparing your statement…</div>
        <div v-else-if="!transactions.length" class="card">
            <div class="card-title">No transactions found</div>
            <p class="card-sub">You can try a different file or keep things manual.</p>
        </div>

        <div v-else class="statement-list">
            <div v-for="(item, index) in transactions" :key="item.id" class="card statement-item">
                <div class="statement-header">
                    <span class="card-title">Entry {{ index + 1 }}</span>
                    <span v-if="item.duplicate" class="duplicate-chip">Looks similar to something you already added</span>
                </div>

                <label class="field">
                    <span>Date</span>
                    <input v-model="item.date" type="date" />
                </label>

                <label class="field">
                    <span>Description</span>
                    <input v-model="item.description" type="text" />
                </label>

                <div class="field">
                    <span>Type</span>
                    <div class="type-toggle">
                        <button
                            type="button"
                            :class="['toggle-button', { active: item.type === 'spending' }]"
                            @click="item.type = 'spending'"
                        >
                            Spending
                        </button>
                        <button
                            type="button"
                            :class="['toggle-button', { active: item.type === 'income' }]"
                            @click="item.type = 'income'"
                        >
                            Income
                        </button>
                    </div>
                </div>

                <label class="field">
                    <span>Amount</span>
                    <input v-model="item.amount" type="number" inputmode="decimal" min="0.01" step="0.01" />
                </label>

                <div class="statement-actions">
                    <button class="ghost-button" type="button" @click="toggleInclude(item)">
                        {{ item.include ? 'Remove' : 'Include' }}
                    </button>
                </div>
            </div>
        </div>

        <div class="statement-footer">
            <button class="ghost-button" type="button" @click="handleDiscard" :disabled="saving">
                Cancel import
            </button>
            <button class="primary-button" type="button" @click="handleConfirm" :disabled="saving">
                {{ saving ? 'Saving…' : 'Confirm import' }}
            </button>
        </div>

        <p v-if="error" class="form-error">{{ error }}</p>
    </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { confirmStatement, discardStatement, fetchStatement } from '../stores/statements';
import { getMonthKey, setMonth } from '../stores/transactions';

const route = useRoute();
const router = useRouter();

const loading = ref(true);
const saving = ref(false);
const error = ref('');
const transactions = ref([]);
const summary = ref(null);
const showNoIncomeNote = computed(() =>
    transactions.value.length > 0 && !transactions.value.some((item) => item.type === 'income')
);
const summaryAvailable = computed(() => transactions.value.length > 0);
const totals = computed(() => {
    const included = transactions.value.filter((item) => item.include !== false);
    return included.reduce(
        (acc, item) => {
            if (item.type === 'income') {
                acc.income += Number.parseFloat(item.amount) || 0;
            } else {
                acc.spending += Number.parseFloat(item.amount) || 0;
            }
            return acc;
        },
        { income: 0, spending: 0 }
    );
});
const statementIncome = computed(() => {
    const value = Number.parseFloat(summary.value?.balance_change);
    if (!Number.isFinite(value) || value <= 0) {
        return null;
    }
    return value;
});

const loadImport = async () => {
    loading.value = true;
    error.value = '';

    try {
        const data = await fetchStatement(route.params.id);
        const list = (data.transactions || []).map((item) => ({
            ...item,
            type: item.type === 'income' ? 'income' : 'spending',
            include: item.include !== false,
            amount: Number.parseFloat(item.amount) || 0,
        }));
        transactions.value = list;
        summary.value = data.meta || null;
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to load this statement.';
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    loadImport();
});

const toggleInclude = (item) => {
    item.include = !item.include;
};

const handleConfirm = async () => {
    saving.value = true;
    error.value = '';

    try {
        const payload = transactions.value.map((item) => ({
            date: item.date,
            description: item.description,
            amount: Number.parseFloat(item.amount) || 0,
            type: item.type === 'income' ? 'income' : 'spending',
            include: !!item.include,
        }));
        const included = payload.filter((item) => item.include);
        if (!included.length) {
            error.value = 'Select at least one entry to import.';
            saving.value = false;
            return;
        }
        await confirmStatement(route.params.id, payload);

        const dates = included.map((item) => item.date).filter(Boolean).sort();
        const firstDate = dates[0];
        if (firstDate) {
            const monthKey = getMonthKey(new Date(firstDate));
            setMonth(monthKey);
        }
        router.push({ name: 'transactions' });
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to import right now.';
    } finally {
        saving.value = false;
    }
};

const handleDiscard = async () => {
    saving.value = true;
    error.value = '';

    try {
        await discardStatement(route.params.id);
        router.push({ name: 'statements' });
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to discard right now.';
    } finally {
        saving.value = false;
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
</script>
