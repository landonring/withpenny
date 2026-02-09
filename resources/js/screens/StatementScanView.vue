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
                <img v-if="currentUrl" :src="currentUrl" alt="Statement page preview" />
                <div v-else class="camera-overlay">
                    <div class="camera-frame"></div>
                    <p class="camera-hint">Line up the page and tap Take photo.</p>
                </div>
            </div>
        </div>

        <div v-if="currentUrl" class="scan-actions">
            <button class="primary-button" type="button" @click="usePage">
                Use this page
            </button>
            <button class="ghost-button" type="button" @click="retakePage">
                Retake
            </button>
        </div>

        <div v-else class="scan-actions">
            <label class="primary-button scan-choice" :class="{ disabled: pages.length >= maxPages }">
                Take photo
                <input
                    ref="cameraInput"
                    type="file"
                    accept="image/*"
                    capture="environment"
                    :disabled="pages.length >= maxPages"
                    @change="handleCamera"
                />
            </label>
            <label class="ghost-button scan-choice" :class="{ disabled: pages.length >= maxPages }">
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
const currentUrl = ref('');
const currentBlob = ref(null);
const error = ref('');
const uploading = ref(false);
const maxPages = 6;

const cameraInput = ref(null);
const libraryInput = ref(null);
const handleCamera = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    if (currentUrl.value) {
        URL.revokeObjectURL(currentUrl.value);
    }
    currentBlob.value = file;
    currentUrl.value = URL.createObjectURL(file);
    event.target.value = '';
};

const usePage = () => {
    if (!currentBlob.value || !currentUrl.value) return;
    pages.value = [
        ...pages.value,
        { id: `${Date.now()}-${pages.value.length}`, url: currentUrl.value, blob: currentBlob.value },
    ];
    currentBlob.value = null;
    currentUrl.value = '';
};

const retakePage = () => {
    if (currentUrl.value) {
        URL.revokeObjectURL(currentUrl.value);
    }
    currentUrl.value = '';
    currentBlob.value = null;
    if (cameraInput.value) {
        cameraInput.value.value = '';
    }
};

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
    if (currentUrl.value) {
        URL.revokeObjectURL(currentUrl.value);
    }
    pages.value.forEach((page) => {
        if (page.url) {
            URL.revokeObjectURL(page.url);
        }
    });
});
</script>
