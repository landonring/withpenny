<template>
    <section class="scan-screen statement-scan">
        <div class="scan-header">
            <div>
                <p class="eyebrow">Bank statements</p>
        <h1 class="screen-title">Scan statements</h1>
        <p class="card-sub">Capture pages across multiple months, then review everything before saving.</p>
                <p v-if="statementRemainingText" class="muted">{{ statementRemainingText }}</p>
                <p v-if="statementLimitReached" class="form-error">You've reached your monthly limit.</p>
                <button v-if="statementLimitReached" class="ghost-button" type="button" @click="openUpgrade">
                    Upgrade
                </button>
            </div>
        </div>

        <div class="scan-actions">
            <label class="primary-button scan-choice" data-onboarding="upload" :class="{ disabled: pages.length >= maxPages }">
                Choose PDF files
                <input
                    ref="libraryInput"
                    type="file"
                    accept=".pdf,application/pdf"
                    :disabled="pages.length >= maxPages || statementLimitReached"
                    multiple
                    @change="handleLibrary"
                />
            </label>
        </div>

        <div v-if="pages.length" class="card page-strip">
            <div class="card-title">Pages captured</div>
            <div class="page-list">
                <div v-for="page in pages" :key="page.id" class="page-thumb">
                    <div class="page-file">
                        <span class="page-file-name">{{ page.name }}</span>
                        <span class="page-file-type">PDF</span>
                    </div>
                    <button class="page-remove" type="button" @click="removePage(page.id)">Remove</button>
                </div>
            </div>
            <p class="muted">Up to {{ maxPages }} statement PDFs across months.</p>
        </div>

        <div class="scan-actions">
            <button class="ghost-button" type="button" @click="handleCancel">Cancel</button>
            <button
                class="primary-button"
                data-onboarding-action="upload-review"
                type="button"
                :disabled="uploading || statementLimitReached || (!pages.length && !onboardingState.mode)"
                @click="handleReview"
            >
                {{ uploading ? 'Preparing…' : (onboardingState.mode && !pages.length ? 'Review sample import' : 'Review import') }}
            </button>
        </div>

        <p v-if="error" class="form-error">{{ error }}</p>
    </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { scanStatementImages } from '../stores/statements';
import { ensureUsageStatus, usageState } from '../stores/usage';
import { showUpgrade } from '../stores/upgrade';
import { ensureOnboardingStatus, onboardingState } from '../stores/onboarding';

const router = useRouter();

const pages = ref([]);
const error = ref('');
const uploading = ref(false);
const maxPages = 12;
const statementUsage = computed(() => usageState.data?.features?.statement_uploads || null);
const statementLimitReached = computed(() => !!statementUsage.value?.exhausted);
const statementRemainingText = computed(() => {
    if (usageState.plan === 'premium') return '';
    if (!statementUsage.value || statementUsage.value.limit === null) return '';
    return `${statementUsage.value.remaining} of ${statementUsage.value.limit} uploads remaining this month`;
});
const openUpgrade = () => {
    showUpgrade(usageState.plan === 'starter' ? 'pro' : 'premium', 'bank statement uploads');
};

const libraryInput = ref(null);

onMounted(() => {
    ensureUsageStatus();
});

const handleLibrary = (event) => {
    const files = Array.from(event.target.files || []).filter((file) => {
        const mime = String(file.type || '').toLowerCase();
        const name = String(file.name || '').toLowerCase();
        return mime === 'application/pdf' || name.endsWith('.pdf');
    });
    if (!files.length) {
        error.value = 'Upload statement PDFs only.';
        event.target.value = '';
        return;
    }

    error.value = '';

    files.slice(0, maxPages - pages.value.length).forEach((file) => {
        pages.value = [
            ...pages.value,
            { id: `${Date.now()}-${Math.random()}`, blob: file, name: file.name || 'statement.pdf' },
        ];
    });

    event.target.value = '';
};

const removePage = (id) => {
    pages.value = pages.value.filter((item) => item.id !== id);
};

const handleReview = async () => {
    if (uploading.value || statementLimitReached.value) return;
    if (!pages.value.length && !onboardingState.mode) return;
    uploading.value = true;
    error.value = '';

    try {
        const importData = await scanStatementImages(pages.value.map((page) => page.blob));
        if (onboardingState.mode) {
            await ensureOnboardingStatus(true);
        }
        await ensureUsageStatus(true);
        router.push({ name: 'statements-review', params: { id: importData.id } });
    } catch (err) {
        const status = err?.response?.status;
        const data = err?.response?.data || {};
        if (status === 409 && data?.redirect_to) {
            await ensureUsageStatus(true);
            router.push(data.redirect_to);
            return;
        }
        const isDateRangeRejection = status === 422
            && data?.feature === 'bank statement uploads'
            && (data?.reason === 'statement_date_span_exceeded' || /date range/i.test(String(data?.message || '')));

        if (isDateRangeRejection) {
            error.value = String(data?.message || 'This statement exceeds the date range included in your current plan.');
            return;
        }

        error.value = err?.response?.data?.message || 'Unable to scan right now.';
        if (status === 429) {
            await ensureUsageStatus(true);
        }
    } finally {
        uploading.value = false;
    }
};

const handleCancel = () => {
    router.push({ name: 'statements' });
};
</script>
