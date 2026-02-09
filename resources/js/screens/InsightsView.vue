<template>
    <section class="screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">Insights</p>
                <h1 class="screen-title">Small signals, big clarity</h1>
            </div>
            <div class="accent-chip">Trends</div>
        </div>

        <div class="card insight-card">
            <div class="card-title">Monthly reflection</div>
            <p class="card-sub">A gentle note about your month, whenever you want it.</p>
            <button class="primary-button" type="button" :disabled="loadingMonthly" @click="handleMonthly">
                {{ loadingMonthly ? 'Reflecting…' : 'Generate reflection' }}
            </button>
            <p class="muted">Usually takes about 20 seconds.</p>
            <div v-if="monthlyReflection" class="ai-card">
                <p>{{ monthlyReflection }}</p>
                <button class="ghost-button" type="button" @click="monthlyReflection = ''">Dismiss</button>
            </div>
        </div>

        <div class="card insight-card">
            <div class="card-title">Weekly check-in</div>
            <p class="card-sub">A short, optional check-in whenever you want one.</p>
            <button class="ghost-button" type="button" :disabled="loadingWeekly" @click="handleWeekly">
                {{ loadingWeekly ? 'Checking in…' : 'Get weekly check-in' }}
            </button>
            <p class="muted">Usually takes about 20 seconds.</p>
            <div v-if="weeklyCheckIn" class="ai-card">
                <p>{{ weeklyCheckIn }}</p>
                <button class="ghost-button" type="button" @click="weeklyCheckIn = ''">Dismiss</button>
            </div>
        </div>
    </section>
</template>

<script setup>
import { ref } from 'vue';
import { generateMonthlyReflection, generateWeeklyCheckIn } from '../stores/ai';
import { transactionsState } from '../stores/transactions';

const monthlyReflection = ref('');
const weeklyCheckIn = ref('');
const loadingMonthly = ref(false);
const loadingWeekly = ref(false);

const handleMonthly = async () => {
    loadingMonthly.value = true;
    monthlyReflection.value = '';

    try {
        monthlyReflection.value = await generateMonthlyReflection(transactionsState.monthKey);
    } catch (err) {
        monthlyReflection.value = 'Penny is resting right now. You can try again in a little while.';
    } finally {
        loadingMonthly.value = false;
    }
};

const handleWeekly = async () => {
    loadingWeekly.value = true;
    weeklyCheckIn.value = '';

    try {
        weeklyCheckIn.value = await generateWeeklyCheckIn();
    } catch (err) {
        weeklyCheckIn.value = 'Penny is resting right now. You can try again in a little while.';
    } finally {
        loadingWeekly.value = false;
    }
};
</script>
