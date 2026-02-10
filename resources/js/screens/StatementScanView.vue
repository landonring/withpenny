<template>
    <section class="scan-screen statement-scan">
        <div class="scan-header">
            <div>
                <p class="eyebrow">Bank statements</p>
                <h1 class="screen-title">Scan a statement</h1>
                <p class="card-sub">Capture each page, then review everything before saving.</p>
            </div>
        </div>

        <div class="scan-stage">
            <div class="camera-view">
                <div class="camera-hint">Choose statement photos to begin.</div>
            </div>
        </div>

        <div class="scan-actions">
            <label class="primary-button scan-choice" :class="{ disabled: pages.length >= maxPages }">
                Choose from photos
                <input
                    ref="libraryInput"
                    type="file"
                    accept="image/*"
                    :disabled="pages.length >= maxPages"
                    multiple
                    @change="handleLibrary"
                />
            </label>
        </div>

        <div v-if="pages.length" class="card page-strip">
            <div class="card-title">Pages captured</div>
            <div class="page-list">
                <div v-for="page in pages" :key="page.id" class="page-thumb">
                    <img :src="page.url" alt="Statement page" />
                    <button class="page-remove" type="button" @click="removePage(page.id)">Remove</button>
                </div>
            </div>
            <p class="muted">Up to {{ maxPages }} pages.</p>
        </div>

        <div class="scan-actions">
            <button class="ghost-button" type="button" @click="handleCancel">Cancel</button>
            <button class="primary-button" type="button" :disabled="uploading || !pages.length" @click="handleReview">
                {{ uploading ? 'Preparing…' : 'Review import' }}
            </button>
        </div>

        <p v-if="error" class="form-error">{{ error }}</p>
    </section>
</template>

<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { useRouter } from 'vue-router';
import { scanStatementImages } from '../stores/statements';

const router = useRouter();

const pages = ref([]);
const error = ref('');
const uploading = ref(false);
const maxPages = 6;

const libraryInput = ref(null);

const handleLibrary = (event) => {
    const files = Array.from(event.target.files || []);
    if (!files.length) return;

    files.slice(0, maxPages - pages.value.length).forEach((file) => {
        const url = URL.createObjectURL(file);
        pages.value = [
            ...pages.value,
            { id: `${Date.now()}-${Math.random()}`, url, blob: file },
        ];
    });

    event.target.value = '';
};

const removePage = (id) => {
    const page = pages.value.find((item) => item.id === id);
    if (page?.url) {
        URL.revokeObjectURL(page.url);
    }
    pages.value = pages.value.filter((item) => item.id !== id);
};

const handleReview = async () => {
    if (!pages.value.length || uploading.value) return;
    uploading.value = true;
    error.value = '';

    try {
        const importData = await scanStatementImages(pages.value.map((page) => page.blob));
        router.push({ name: 'statements-review', params: { id: importData.id } });
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to scan right now.';
    } finally {
        uploading.value = false;
    }
};

const handleCancel = () => {
    router.push({ name: 'statements' });
};

onBeforeUnmount(() => {
    pages.value.forEach((page) => {
        if (page.url) {
            URL.revokeObjectURL(page.url);
        }
    });
});
</script>
