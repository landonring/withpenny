<template>
    <StatementProcessingView v-if="statementBetaEnabled && route.name === 'statements-processing'" />
    <StatementReviewView v-else-if="statementBetaEnabled && route.name === 'statements-review'" />
    <StatementScanView v-else-if="statementBetaEnabled" />
    <section v-else class="screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">Bank statements</p>
                <h1 class="screen-title">Bank statements are not available yet</h1>
            </div>
            <div class="accent-chip">Soon</div>
        </div>

        <div class="card">
            <p class="card-sub">This feature is currently limited to the statement beta.</p>
            <router-link class="ghost-button" :to="{ name: 'home' }">
                Back home
            </router-link>
        </div>
    </section>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import StatementProcessingView from './StatementProcessingView.vue';
import StatementReviewView from './StatementReviewView.vue';
import StatementScanView from './StatementScanView.vue';
import { ensureUsageStatus, usageState } from '../stores/usage';

const route = useRoute();
const statementBetaEnabled = computed(() => !!usageState.data?.features?.statement_uploads?.beta_enabled);

onMounted(() => {
    ensureUsageStatus();
});
</script>
