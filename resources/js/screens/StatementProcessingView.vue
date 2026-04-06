<template>
    <section class="screen statement-processing">
        <div class="screen-header">
            <div>
                <p class="eyebrow">Import</p>
                <h1 class="screen-title">Analyzing your statement</h1>
            </div>
            <div class="accent-chip">Processing</div>
        </div>

        <div class="card statement-processing-card">
            <div class="statement-processing-spinner" aria-hidden="true"></div>
            <div class="statement-processing-copy">
                <p class="card-sub">We're analyzing your statement now. You will be taken to review as soon as it is ready.</p>
                <p class="muted">Status: {{ statusLabel }}</p>
                <p v-if="processingError" class="form-error">{{ processingError }}</p>
            </div>
        </div>

        <div class="statement-footer">
            <button class="ghost-button" type="button" @click="backToScan">
                Cancel import
            </button>
            <button
                class="primary-button"
                type="button"
                :disabled="isProcessing && !processingError"
                @click="openReview"
            >
                Open review
            </button>
        </div>

        <p v-if="error" class="form-error">{{ error }}</p>
    </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { fetchStatement } from '../stores/statements';

const route = useRoute();
const router = useRouter();

const processingStatus = ref('processing');
const processingError = ref('');
const error = ref('');
const attempts = ref(0);
const maxPollAttempts = 50;
const pollDelayMs = 1500;
let pollTimer = null;

const importId = computed(() => String(route.params.id || ''));
const isProcessing = computed(() =>
    ['pending', 'queued', 'processing'].includes(processingStatus.value)
);
const statusLabel = computed(() => {
    if (processingStatus.value === 'pending') return 'Pending';
    if (processingStatus.value === 'queued') return 'Queued';
    if (processingStatus.value === 'processing') return 'Processing';
    if (processingStatus.value === 'failed') return 'Needs review';
    if (processingStatus.value === 'completed') return 'Ready';
    return 'Processing';
});

const clearPoll = () => {
    if (!pollTimer) return;
    clearTimeout(pollTimer);
    pollTimer = null;
};

const openReview = () => {
    router.replace({ name: 'statements-review', params: { id: importId.value } });
};

const pollImport = async () => {
    if (!importId.value) {
        error.value = 'Missing import id. Please upload again.';
        return;
    }

    try {
        const data = await fetchStatement(importId.value);
        processingStatus.value = String(data.processing_status || 'completed');
        processingError.value = String(data.processing_error || '');

        if (!isProcessing.value) {
            openReview();
            return;
        }

        attempts.value += 1;
        if (attempts.value >= maxPollAttempts) {
            processingError.value = 'Processing is taking longer than expected. You can open review or retry the upload.';
            return;
        }

        clearPoll();
        pollTimer = setTimeout(() => {
            pollImport();
        }, pollDelayMs);
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to check statement status right now.';
    }
};

const backToScan = () => {
    router.push({ name: 'statements-scan' });
};

onMounted(() => {
    pollImport();
});

onBeforeUnmount(() => {
    clearPoll();
});
</script>
