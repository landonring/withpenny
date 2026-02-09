import { reactive } from 'vue';
import axios from 'axios';
import { categoryGroups } from '../data/categories';
import { authState, ensureAuthReady } from './auth';

const STORAGE_PREFIX = 'penny';

async function ensureCsrf(force = false) {
    if (!force && window.axios?.defaults?.headers?.common?.['X-CSRF-TOKEN']) {
        return;
    }
    try {
        const { data } = await axios.get('/api/csrf');
        if (data?.csrf_token) {
            window.axios.defaults.headers.common['X-CSRF-TOKEN'] = data.csrf_token;
        }
    } catch {
        // ignore
    }
}

const state = reactive({
    monthKey: getMonthKey(new Date()),
    transactions: [],
    summary: {
        total: 0,
        spendingTotal: 0,
        incomeTotal: 0,
        moneyIn: 0,
        net: 0,
        count: 0,
        topCategory: '',
        breakdown: {
            Needs: 0,
            Wants: 0,
            Future: 0,
        },
    },
    income: 0,
    futureTotal: 0,
    loading: false,
    syncing: false,
    ready: false,
    userId: null,
});

function storageKeyForMonth(monthKey, userId) {
    return `${STORAGE_PREFIX}.transactions.${userId}.${monthKey}`;
}

function queueKey(userId) {
    return `${STORAGE_PREFIX}.queue.${userId}`;
}

function incomeKey(monthKey, userId) {
    return `${STORAGE_PREFIX}.income.${userId}.${monthKey}`;
}

function futureKey(monthKey, userId) {
    return `${STORAGE_PREFIX}.future.${userId}.${monthKey}`;
}

function getMonthKey(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    return `${year}-${month}`;
}

function getMonthKeyFromDateString(dateString) {
    if (!dateString) return state.monthKey;
    const parts = dateString.split('-');
    if (parts.length < 2) return state.monthKey;
    const year = Number.parseInt(parts[0], 10);
    const month = Number.parseInt(parts[1], 10);
    if (!year || !month) return state.monthKey;
    return `${year}-${String(month).padStart(2, '0')}`;
}

function parseAmount(value) {
    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : 0;
}

function loadMonthFromStorage(monthKey) {
    const userId = authState.user?.id;
    if (!userId) return [];
    const raw = localStorage.getItem(storageKeyForMonth(monthKey, userId));
    if (!raw) return [];
    try {
        return JSON.parse(raw);
    } catch (error) {
        return [];
    }
}

function saveMonthToStorage(monthKey, transactions) {
    const userId = authState.user?.id;
    if (!userId) return;
    localStorage.setItem(storageKeyForMonth(monthKey, userId), JSON.stringify(transactions));
}

function loadQueue() {
    const userId = authState.user?.id;
    if (!userId) return [];
    const raw = localStorage.getItem(queueKey(userId));
    if (!raw) return [];
    try {
        return JSON.parse(raw);
    } catch (error) {
        return [];
    }
}

function saveQueue(queue) {
    const userId = authState.user?.id;
    if (!userId) return;
    localStorage.setItem(queueKey(userId), JSON.stringify(queue));
}

function loadIncome(monthKey) {
    const userId = authState.user?.id;
    if (!userId) return 0;
    const raw = localStorage.getItem(incomeKey(monthKey, userId));
    const parsed = Number.parseFloat(raw);
    return Number.isFinite(parsed) ? parsed : 0;
}

function loadFuture(monthKey) {
    const userId = authState.user?.id;
    if (!userId) return 0;
    const raw = localStorage.getItem(futureKey(monthKey, userId));
    const parsed = Number.parseFloat(raw);
    return Number.isFinite(parsed) ? parsed : 0;
}

function saveIncome(monthKey, value) {
    const userId = authState.user?.id;
    if (!userId) return;
    localStorage.setItem(incomeKey(monthKey, userId), String(value));
}

function saveFuture(monthKey, value) {
    const userId = authState.user?.id;
    if (!userId) return;
    localStorage.setItem(futureKey(monthKey, userId), String(value));
}

function computeSummary(transactions, futureTotal = 0, manualIncome = 0) {
    const totals = {
        Needs: 0,
        Wants: 0,
        Future: parseAmount(futureTotal),
    };
    const byCategory = {};
    let incomeTotal = 0;

    transactions.forEach((transaction) => {
        const type = transaction.type === 'income' ? 'income' : 'spending';
        const amount = parseAmount(transaction.amount);
        if (type === 'income') {
            incomeTotal += amount;
            return;
        }
        const group = categoryGroups[transaction.category] || 'Wants';
        totals[group] += amount;
        byCategory[transaction.category] = (byCategory[transaction.category] || 0) + amount;
    });

    let topCategory = '';
    let topAmount = 0;
    Object.entries(byCategory).forEach(([category, amount]) => {
        if (amount > topAmount) {
            topAmount = amount;
            topCategory = category;
        }
    });

    const spendingTotal = totals.Needs + totals.Wants;
    const total = spendingTotal + totals.Future;
    const moneyIn = incomeTotal + parseAmount(manualIncome);
    const net = moneyIn - spendingTotal;

    return {
        total,
        spendingTotal,
        incomeTotal,
        moneyIn,
        net,
        count: transactions.length,
        topCategory,
        breakdown: totals,
    };
}

function sortTransactions(transactions) {
    return [...transactions].sort((a, b) => {
        const dateDiff = new Date(b.transaction_date) - new Date(a.transaction_date);
        if (dateDiff !== 0) return dateDiff;
        return (b.id || '').toString().localeCompare((a.id || '').toString());
    });
}

function mergeTransactions(serverTransactions, localTransactions) {
    const localPending = localTransactions.filter((item) => item.syncState === 'pending');
    return sortTransactions([...serverTransactions, ...localPending]);
}

function setStateTransactions(transactions, monthKey, futureOverride = null) {
    const sorted = sortTransactions(transactions);
    saveMonthToStorage(monthKey, sorted);

    if (futureOverride !== null && futureOverride !== undefined) {
        saveFuture(monthKey, futureOverride);
        if (monthKey === state.monthKey) {
            state.futureTotal = futureOverride;
        }
    }

    if (monthKey === state.monthKey) {
        state.transactions = sorted;
        state.summary = computeSummary(sorted, state.futureTotal, state.income);
    }
}

function upsertTransactionInMonth(monthKey, transaction) {
    const list = loadMonthFromStorage(monthKey);
    const index = list.findIndex((item) => String(item.id) === String(transaction.id));
    const next = index === -1
        ? [transaction, ...list]
        : list.map((item) => (String(item.id) === String(transaction.id) ? transaction : item));
    setStateTransactions(next, monthKey);
}

function removeTransactionFromMonth(monthKey, id) {
    const list = loadMonthFromStorage(monthKey).filter((item) => String(item.id) !== String(id));
    setStateTransactions(list, monthKey);
}

async function fetchTransactions(monthKey = state.monthKey) {
    await ensureAuthReady();
    if (!authState.user) return;

    state.loading = true;
    const localTransactions = loadMonthFromStorage(monthKey);

    try {
        if (!navigator.onLine) {
            setStateTransactions(localTransactions, monthKey);
            state.loading = false;
            return;
        }

        const response = await axios.get('/api/transactions', {
            params: { month: monthKey },
        });

        const futureTotal = parseAmount(response.data.future_total);
        const merged = mergeTransactions(response.data.transactions || [], localTransactions);
        setStateTransactions(merged, monthKey, futureTotal);
    } catch (error) {
        setStateTransactions(localTransactions, monthKey);
    } finally {
        state.loading = false;
        syncQueue();
    }
}

function setMonth(monthKey) {
    state.monthKey = monthKey;
    state.income = loadIncome(monthKey);
    state.futureTotal = loadFuture(monthKey);
    setStateTransactions(loadMonthFromStorage(monthKey), monthKey);
    fetchTransactions(monthKey);
}

function updateIncome(value) {
    const sanitized = parseAmount(value);
    state.income = sanitized;
    saveIncome(state.monthKey, sanitized);
    state.summary = computeSummary(state.transactions, state.futureTotal, state.income);
}

async function addTransaction(payload) {
    await ensureAuthReady();

    if (!authState.user) return;

    const monthKey = getMonthKeyFromDateString(payload.transaction_date);

    if (!navigator.onLine) {
        const tempId = `local-${Date.now()}`;
        const transaction = {
            id: tempId,
            ...payload,
            syncState: 'pending',
        };

        upsertTransactionInMonth(monthKey, transaction);

        const queue = loadQueue();
        queue.push({
            id: tempId,
            action: 'create',
            payload,
            monthKey,
        });
        saveQueue(queue);
        return transaction;
    }

    await ensureCsrf(true);
    const response = await axios.post('/api/transactions', payload);
    const created = response.data.transaction;
    const createdMonth = getMonthKeyFromDateString(created.transaction_date);
    upsertTransactionInMonth(createdMonth, created);
    return created;
}

async function updateTransaction(id, payload) {
    await ensureAuthReady();
    if (!authState.user) return null;

    const existing = state.transactions.find((transaction) => String(transaction.id) === String(id));
    const previousMonthKey = existing
        ? getMonthKeyFromDateString(existing.transaction_date)
        : state.monthKey;

    if (!navigator.onLine || String(id).startsWith('local-')) {
        const updated = { ...existing, ...payload, id, syncState: 'pending' };
        const nextMonthKey = getMonthKeyFromDateString(updated.transaction_date);

        if (previousMonthKey !== nextMonthKey) {
            removeTransactionFromMonth(previousMonthKey, id);
        }

        upsertTransactionInMonth(nextMonthKey, updated);

        const queue = loadQueue();
        const existingCreate = queue.find((item) => item.action === 'create' && item.id === id);

        if (existingCreate) {
            existingCreate.payload = { ...existingCreate.payload, ...payload };
        } else if (!String(id).startsWith('local-')) {
            queue.push({
                id,
                action: 'update',
                payload,
                monthKey: previousMonthKey,
            });
        }

        saveQueue(queue);
        return updated;
    }

    await ensureCsrf(true);
    const response = await axios.put(`/api/transactions/${id}`, payload);
    const updated = response.data.transaction;
    const updatedMonthKey = getMonthKeyFromDateString(updated.transaction_date);

    if (previousMonthKey !== updatedMonthKey) {
        removeTransactionFromMonth(previousMonthKey, id);
    }

    upsertTransactionInMonth(updatedMonthKey, updated);
    return updated;
}

async function deleteTransaction(id) {
    await ensureAuthReady();
    if (!authState.user) return;

    const existing = state.transactions.find((transaction) => String(transaction.id) === String(id));
    const monthKey = existing ? getMonthKeyFromDateString(existing.transaction_date) : state.monthKey;

    if (!navigator.onLine || String(id).startsWith('local-')) {
        removeTransactionFromMonth(monthKey, id);

        const queue = loadQueue().filter((item) => item.id !== id);
        if (!String(id).startsWith('local-')) {
            queue.push({ id, action: 'delete', monthKey });
        }
        saveQueue(queue);
        return;
    }

    await ensureCsrf(true);
    await axios.delete(`/api/transactions/${id}`);
    removeTransactionFromMonth(monthKey, id);
}

async function syncQueue() {
    await ensureAuthReady();
    if (!navigator.onLine || !authState.user || state.syncing) return;

    const queue = loadQueue();
    if (!queue.length) return;

    state.syncing = true;
    await ensureCsrf(true);

    const remaining = [];

    for (const item of queue) {
        try {
            if (item.action === 'create') {
                const response = await axios.post('/api/transactions', item.payload);
                const created = response.data.transaction;
                const createdMonthKey = getMonthKeyFromDateString(created.transaction_date);
                removeTransactionFromMonth(item.monthKey || createdMonthKey, item.id);
                upsertTransactionInMonth(createdMonthKey, created);
            }

            if (item.action === 'update') {
                const response = await axios.put(`/api/transactions/${item.id}`, item.payload);
                const updated = response.data.transaction;
                const updatedMonthKey = getMonthKeyFromDateString(updated.transaction_date);
                const previousMonthKey = item.monthKey || updatedMonthKey;

                if (previousMonthKey !== updatedMonthKey) {
                    removeTransactionFromMonth(previousMonthKey, item.id);
                }

                upsertTransactionInMonth(updatedMonthKey, updated);
            }

            if (item.action === 'delete') {
                await axios.delete(`/api/transactions/${item.id}`);
                removeTransactionFromMonth(item.monthKey || state.monthKey, item.id);
            }
        } catch (error) {
            remaining.push(item);
        }
    }

    saveQueue(remaining);
    state.syncing = false;
}

async function initTransactions() {
    await ensureAuthReady();
    const userId = authState.user?.id;
    if (!userId) return;
    if (state.userId !== userId) {
        state.userId = userId;
        state.ready = false;
    }
    if (state.ready) return;
    state.ready = true;
    state.income = loadIncome(state.monthKey);
    state.futureTotal = loadFuture(state.monthKey);
    setStateTransactions(loadMonthFromStorage(state.monthKey), state.monthKey);
    fetchTransactions(state.monthKey);
    window.addEventListener('online', () => {
        syncQueue();
        fetchTransactions(state.monthKey);
    });
}

function getTransactionById(id) {
    return state.transactions.find((transaction) => String(transaction.id) === String(id));
}

async function addReceiptTransaction(receiptId, payload) {
    await ensureAuthReady();
    if (!authState.user) return null;

    if (!navigator.onLine) {
        throw new Error('offline');
    }

    const response = await axios.post(`/api/receipts/${receiptId}/confirm`, payload);
    const created = response.data.transaction;
    const monthKey = getMonthKeyFromDateString(created.transaction_date);
    upsertTransactionInMonth(monthKey, created);
    return created;
}

function applyFutureContribution(amount, dateString = null) {
    const monthKey = getMonthKeyFromDateString(dateString || state.monthKey);
    const current = loadFuture(monthKey);
    const next = current + parseAmount(amount);
    saveFuture(monthKey, next);

    if (monthKey === state.monthKey) {
        state.futureTotal = next;
        state.summary = computeSummary(state.transactions, next, state.income);
    }
}

export {
    state as transactionsState,
    initTransactions,
    fetchTransactions,
    setMonth,
    updateIncome,
    addTransaction,
    addReceiptTransaction,
    updateTransaction,
    deleteTransaction,
    getTransactionById,
    getMonthKey,
    applyFutureContribution,
};
