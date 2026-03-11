<template>
    <section class="screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">Insights</p>
                <h1 class="screen-title">Small signals, big clarity</h1>
                <p class="ai-disclaimer">Penny AI can make mistakes. Check important info.</p>
            </div>
            <div class="accent-chip">Trends</div>
        </div>

        <div class="card insight-card" data-onboarding="insights">
            <div class="card-title">Budget spreadsheet</div>
            <p class="card-sub">Download a clear monthly spreadsheet with totals, categories, and transactions.</p>
            <button
                class="primary-button"
                type="button"
                :disabled="loadingSpreadsheet || spreadsheetLocked"
                @click="handleSpreadsheetExport"
            >
                {{ loadingSpreadsheet ? 'Generating…' : 'Download budget sheet' }}
            </button>
            <p v-if="spreadsheetUsageText" class="muted">{{ spreadsheetUsageText }}</p>
            <p v-if="spreadsheetLocked && spreadsheetIsZeroLimit" class="muted">Included on Pro and Premium.</p>
            <p v-else-if="spreadsheetLocked" class="form-error">{{ spreadsheetLimitMessage }}</p>
            <button v-if="spreadsheetLocked" class="ghost-button" type="button" @click="openSpreadsheetUpgrade">Upgrade</button>
            <p v-if="spreadsheetNotice" :class="spreadsheetNoticeType === 'error' ? 'form-error' : 'muted'">
                {{ spreadsheetNotice }}
            </p>
        </div>

        <div class="card insight-card" data-onboarding="insights">
            <div class="card-title">Yearly overview</div>
            <p class="card-sub">A calm look at the year so far.</p>
            <button
                class="primary-button"
                data-onboarding-action="insight-yearly-generate"
                data-onboarding="insight-yearly-button"
                type="button"
                :disabled="loadingYearly || yearlyLocked"
                @click="handleYearly"
            >
                {{ loadingYearly ? 'Generating…' : 'Generate yearly overview' }}
            </button>
            <p v-if="yearlyUsageText" class="muted">{{ yearlyUsageText }}</p>
            <p v-if="yearlyLocked && yearlyIsZeroLimit" class="muted">Included on Pro and Premium.</p>
            <p v-else-if="yearlyLocked" class="form-error">{{ yearlyLimitMessage }}</p>
            <button v-if="yearlyLocked" class="ghost-button" type="button" @click="openUpgrade">Upgrade</button>
            <p class="muted">Usually takes about 20 seconds.</p>
            <div v-if="yearlyReflection" class="ai-card" data-onboarding="insight-result">
                <p>{{ yearlyReflection }}</p>
                <button class="ghost-button" type="button" @click="yearlyReflection = ''">Dismiss</button>
            </div>
        </div>

        <div class="card insight-card">
            <div class="card-title">Monthly overview</div>
            <p class="card-sub">A gentle note about your month, whenever you want it.</p>
            <button class="primary-button" type="button" :disabled="loadingMonthly || monthlyLocked" @click="handleMonthly">
                {{ loadingMonthly ? 'Generating…' : 'Generate monthly overview' }}
            </button>
            <p v-if="monthlyUsageText" class="muted">{{ monthlyUsageText }}</p>
            <p v-if="monthlyLocked && monthlyIsZeroLimit" class="muted">Included on Pro and Premium.</p>
            <p v-else-if="monthlyLocked" class="form-error">{{ monthlyLimitMessage }}</p>
            <button v-if="monthlyLocked" class="ghost-button" type="button" @click="openUpgrade">Upgrade</button>
            <p class="muted">Usually takes about 20 seconds.</p>
            <div v-if="monthlyReflection" class="ai-card">
                <p>{{ monthlyReflection }}</p>
                <button class="ghost-button" type="button" @click="monthlyReflection = ''">Dismiss</button>
            </div>
        </div>

        <div class="card insight-card">
            <div class="card-title">Weekly check-in</div>
            <p class="card-sub">A short, optional check-in whenever you want one.</p>
            <button class="primary-button" type="button" :disabled="loadingWeekly || weeklyLocked" @click="handleWeekly">
                {{ loadingWeekly ? 'Checking in…' : 'Get weekly check-in' }}
            </button>
            <p v-if="weeklyUsageText" class="muted">{{ weeklyUsageText }}</p>
            <p v-if="weeklyLocked && weeklyIsZeroLimit" class="muted">Included on Pro and Premium.</p>
            <p v-else-if="weeklyLocked" class="form-error">{{ weeklyLimitMessage }}</p>
            <button v-if="weeklyLocked" class="ghost-button" type="button" @click="openUpgrade">Upgrade</button>
            <p class="muted">Usually takes about 20 seconds.</p>
            <div v-if="weeklyCheckIn" class="ai-card">
                <p>{{ weeklyCheckIn }}</p>
                <button class="ghost-button" type="button" @click="weeklyCheckIn = ''">Dismiss</button>
            </div>
        </div>

        <div class="card insight-card">
            <div class="card-title">Daily overview</div>
            <p class="card-sub">A quick note about today so far.</p>
            <button class="primary-button" type="button" :disabled="loadingDaily || dailyLocked" @click="handleDaily">
                {{ loadingDaily ? 'Generating…' : 'Generate daily overview' }}
            </button>
            <p v-if="dailyUsageText" class="muted">{{ dailyUsageText }}</p>
            <p v-if="dailyLocked && dailyIsZeroLimit" class="muted">Included on Pro and Premium.</p>
            <p v-else-if="dailyLocked" class="form-error">{{ dailyLimitMessage }}</p>
            <button v-if="dailyLocked" class="ghost-button" type="button" @click="openUpgrade">Upgrade</button>
            <p class="muted">Usually takes about 20 seconds.</p>
            <div v-if="dailyOverview" class="ai-card">
                <p>{{ dailyOverview }}</p>
                <button class="ghost-button" type="button" @click="dailyOverview = ''">Dismiss</button>
            </div>
        </div>
    </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { generateDailyOverview, generateMonthlyReflection, generateWeeklyCheckIn, generateYearlyReflection } from '../stores/ai';
import { generateSpreadsheet } from '../stores/spreadsheets';
import { transactionsState } from '../stores/transactions';
import { ensureUsageStatus, usageState } from '../stores/usage';
import { showUpgrade } from '../stores/upgrade';

const monthlyReflection = ref('');
const dailyOverview = ref('');
const yearlyReflection = ref('');
const weeklyCheckIn = ref('');
const loadingDaily = ref(false);
const loadingMonthly = ref(false);
const loadingYearly = ref(false);
const loadingWeekly = ref(false);
const loadingSpreadsheet = ref(false);
const spreadsheetNotice = ref('');
const spreadsheetNoticeType = ref('info');
const currentYear = new Date().getFullYear();
const todayKey = new Date().toISOString().slice(0, 10);
const insightUsage = computed(() => usageState.data?.insights || null);
const featureUsage = computed(() => usageState.data?.features || null);
const isPremium = computed(() => usageState.plan === 'premium');
const monthlyLimit = computed(() => insightUsage.value?.monthly || null);
const weeklyLimit = computed(() => insightUsage.value?.weekly || null);
const dailyLimit = computed(() => insightUsage.value?.daily || null);
const yearlyLimit = computed(() => insightUsage.value?.yearly || null);
const spreadsheetLimit = computed(() => featureUsage.value?.spreadsheet_exports || null);

const monthlyLocked = computed(() => !!monthlyLimit.value?.exhausted);
const weeklyLocked = computed(() => !!weeklyLimit.value?.exhausted);
const dailyLocked = computed(() => !!dailyLimit.value?.exhausted);
const yearlyLocked = computed(() => !!yearlyLimit.value?.exhausted);
const spreadsheetLocked = computed(() => !!spreadsheetLimit.value?.exhausted);
const monthlyIsZeroLimit = computed(() => (monthlyLimit.value?.limit ?? null) === 0);
const weeklyIsZeroLimit = computed(() => (weeklyLimit.value?.limit ?? null) === 0);
const dailyIsZeroLimit = computed(() => (dailyLimit.value?.limit ?? null) === 0);
const yearlyIsZeroLimit = computed(() => (yearlyLimit.value?.limit ?? null) === 0);
const spreadsheetIsZeroLimit = computed(() => (spreadsheetLimit.value?.limit ?? null) === 0);

const usageText = (entry, noun, period = 'month') => {
    if (!entry || entry.limit === null || entry.limit === 0 || isPremium.value) return '';
    return `${entry.remaining} of ${entry.limit} ${noun} remaining this ${period}`;
};

const lockedMessage = (entry, periodLabel) => {
    if (!entry?.exhausted) return '';
    if (entry.limit === 0) return 'Included on Pro and Premium.';
    return `You've reached your ${periodLabel} limit.`;
};

const monthlyUsageText = computed(() => usageText(monthlyLimit.value, 'monthly overviews'));
const weeklyUsageText = computed(() => usageText(weeklyLimit.value, 'weekly check-ins'));
const dailyUsageText = computed(() => usageText(dailyLimit.value, 'daily overviews'));
const yearlyUsageText = computed(() => usageText(yearlyLimit.value, 'yearly overviews', 'year'));
const spreadsheetUsageText = computed(() => usageText(spreadsheetLimit.value, 'spreadsheets'));
const monthlyLimitMessage = computed(() => lockedMessage(monthlyLimit.value, 'monthly'));
const weeklyLimitMessage = computed(() => lockedMessage(weeklyLimit.value, 'monthly'));
const dailyLimitMessage = computed(() => lockedMessage(dailyLimit.value, 'monthly'));
const yearlyLimitMessage = computed(() => lockedMessage(yearlyLimit.value, 'yearly'));
const spreadsheetLimitMessage = computed(() => lockedMessage(spreadsheetLimit.value, 'monthly'));
const openUpgrade = () => {
    showUpgrade(usageState.plan === 'starter' ? 'pro' : 'premium', 'insights');
};
const openSpreadsheetUpgrade = () => {
    showUpgrade(usageState.plan === 'starter' ? 'pro' : 'premium', 'spreadsheet exports');
};

onMounted(() => {
    ensureUsageStatus();
    axios.post('/api/usage/activity', { activity: 'insight_viewed' }).catch(() => {});
});

const insightErrorMessage = (err) => {
    const message = err?.response?.data?.message;
    return message || 'Penny is resting right now. You can try again in a little while.';
};

const spreadsheetErrorMessage = (err) => {
    const message = err?.response?.data?.message;
    return message || 'We could not generate your spreadsheet right now. Please try again in a moment.';
};

const handleSpreadsheetExport = async () => {
    if (spreadsheetLocked.value) return;
    loadingSpreadsheet.value = true;
    spreadsheetNotice.value = '';
    spreadsheetNoticeType.value = 'info';

    try {
        await generateSpreadsheet();
        spreadsheetNotice.value = 'Spreadsheet downloaded.';
        await ensureUsageStatus(true);
    } catch (err) {
        spreadsheetNotice.value = spreadsheetErrorMessage(err);
        spreadsheetNoticeType.value = 'error';
        await ensureUsageStatus(true);
    } finally {
        loadingSpreadsheet.value = false;
    }
};

const handleMonthly = async () => {
    if (monthlyLocked.value) return;
    loadingMonthly.value = true;
    monthlyReflection.value = '';

    try {
        monthlyReflection.value = await generateMonthlyReflection(transactionsState.monthKey);
        await ensureUsageStatus(true);
    } catch (err) {
        monthlyReflection.value = insightErrorMessage(err);
        await ensureUsageStatus(true);
    } finally {
        loadingMonthly.value = false;
    }
};

const handleYearly = async () => {
    if (yearlyLocked.value) return;
    loadingYearly.value = true;
    yearlyReflection.value = '';

    try {
        yearlyReflection.value = await generateYearlyReflection(currentYear);
        await ensureUsageStatus(true);
    } catch (err) {
        yearlyReflection.value = insightErrorMessage(err);
        await ensureUsageStatus(true);
    } finally {
        loadingYearly.value = false;
    }
};

const handleWeekly = async () => {
    if (weeklyLocked.value) return;
    loadingWeekly.value = true;
    weeklyCheckIn.value = '';

    try {
        weeklyCheckIn.value = await generateWeeklyCheckIn();
        await ensureUsageStatus(true);
    } catch (err) {
        weeklyCheckIn.value = insightErrorMessage(err);
        await ensureUsageStatus(true);
    } finally {
        loadingWeekly.value = false;
    }
};

const handleDaily = async () => {
    if (dailyLocked.value) return;
    loadingDaily.value = true;
    dailyOverview.value = '';

    try {
        dailyOverview.value = await generateDailyOverview(todayKey);
        await ensureUsageStatus(true);
    } catch (err) {
        dailyOverview.value = insightErrorMessage(err);
        await ensureUsageStatus(true);
    } finally {
        loadingDaily.value = false;
    }
};
</script>
