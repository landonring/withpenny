<template>
    <StatementProcessingView v-if="route.name === 'statements-processing'" />
    <StatementReviewView v-else-if="route.name === 'statements-review'" />
    <StatementScanView v-else />
</template>

<script setup>
import { onMounted } from 'vue';
import { useRoute } from 'vue-router';
import StatementProcessingView from './StatementProcessingView.vue';
import StatementReviewView from './StatementReviewView.vue';
import StatementScanView from './StatementScanView.vue';
import { ensureUsageStatus } from '../stores/usage';

const route = useRoute();

onMounted(() => {
    ensureUsageStatus();
});
</script>
