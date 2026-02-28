<template>
    <section class="screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">Bank statements</p>
                <h1 class="screen-title">Bring in past activity</h1>
            </div>
            <div class="accent-chip">Optional</div>
        </div>

        <div class="card">
            <p class="card-sub">
                Uploading statements can save time. You can bring in multiple months if you want.
            </p>
            <p v-if="statementRemainingText" class="muted">{{ statementRemainingText }}</p>
            <p v-if="statementLimitReached" class="form-error">You've reached your monthly limit.</p>
            <button v-if="statementLimitReached" class="ghost-button" type="button" @click="openUpgrade">
                Upgrade
            </button>

            <div class="statement-entry-actions">
                <button class="primary-button" type="button" :disabled="statementLimitReached" @click="openStatementsScan">
                    Choose statement PDFs
                </button>
            </div>
            <p class="muted">
                Choose statement PDF files. You can select multiple files and months at once.
            </p>

            <p v-if="error" class="form-error">{{ error }}</p>
            <p v-if="uploading" class="muted">Parsing your statement…</p>
        </div>

        <div class="card">
            <div class="card-title">What gets stored</div>
            <p class="card-sub">
                Penny keeps only date, description, and amount. Files are discarded after parsing.
            </p>
        </div>
    </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ensureUsageStatus, usageState } from '../stores/usage';
import { showUpgrade } from '../stores/upgrade';

const error = ref('');
const uploading = ref(false);
const router = useRouter();
const statementUsage = computed(() => usageState.data?.features?.statement_uploads || null);
const statementLimitReached = computed(() => !!statementUsage.value?.exhausted);
const statementRemainingText = computed(() => {
    if (usageState.plan === 'premium') return '';
    if (!statementUsage.value || statementUsage.value.limit === null) return '';
    return `${statementUsage.value.remaining} of ${statementUsage.value.limit} uploads remaining this month`;
});

onMounted(() => {
    ensureUsageStatus();
});

const openStatementsScan = () => {
    if (statementLimitReached.value) return;
    router.push({ name: 'statements-scan' });
};

const openUpgrade = () => {
    showUpgrade(usageState.plan === 'starter' ? 'pro' : 'premium', 'bank statement uploads');
};
</script>
