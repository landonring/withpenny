<template>
    <section :class="['scan-screen', { scrollable: photos.length }]">
        <div class="scan-header">
            <div>
                <p class="eyebrow">Scan</p>
                <h1 class="screen-title">Take a photo of your receipt</h1>
                <p class="card-sub">We'll help pull out the details after you confirm the photo.</p>
                <p v-if="receiptRemainingText" class="muted">{{ receiptRemainingText }}</p>
                <p v-if="receiptLimitReached" class="form-error">You've reached your monthly limit.</p>
                <button v-if="receiptLimitReached" class="ghost-button" type="button" @click="openUpgrade">
                    Upgrade
                </button>
            </div>
        </div>

        <div v-if="!photos.length" class="card scan-guide">
            <div class="card-title">Choose a clear receipt photo</div>
            <p class="card-sub">Good light and a full page makes the scan feel smoother.</p>
            <div class="scan-tip-list">
                <p>Include the full receipt edge to edge.</p>
                <p>Keep the text sharp and easy to read.</p>
                <p>No pressure. You can adjust anything later.</p>
            </div>
        </div>

        <div v-if="photos.length" class="scan-stage">
            <div class="camera-view receipt-multi">
                <div class="receipt-preview-grid">
                    <div v-for="(photo, index) in photos" :key="photo.id" class="receipt-preview">
                        <img :src="photo.url" alt="Receipt preview" />
                        <button class="remove-preview" type="button" @click="removePhoto(index)">
                            Remove
                        </button>
                    </div>
                </div>
            </div>
            <p class="muted scan-count">{{ photos.length }} of 7 photos</p>
        </div>

        <div v-if="!photos.length" class="scan-actions">
            <label class="primary-button scan-choice">
                Choose from photos
                <input
                    ref="libraryInput"
                    type="file"
                    accept="image/jpeg,image/png,image/heic,image/heif"
                    multiple
                    :disabled="processing || receiptLimitReached"
                    @change="handleFile"
                />
            </label>
        </div>

        <div v-else class="scan-actions">
            <button class="primary-button" type="button" :disabled="uploading || processing || receiptLimitReached" @click="usePhotos">
                {{ uploading || processing ? 'Preparing…' : 'Use these photos' }}
            </button>
            <label class="ghost-button scan-choice" :class="{ disabled: photos.length >= maxPhotos }">
                Add more photos
                <input
                    ref="libraryInput"
                    type="file"
                    accept="image/jpeg,image/png,image/heic,image/heif"
                    multiple
                    :disabled="photos.length >= maxPhotos || processing || receiptLimitReached"
                    @change="handleFile"
                />
            </label>
            <button class="ghost-button" type="button" :disabled="uploading || processing || receiptLimitReached" @click="clearPhotos">
                Clear all
            </button>
        </div>

        <p v-if="error" class="form-error">{{ error }}</p>
        <router-link v-if="error" class="ghost-button" :to="{ name: 'transactions-new' }">
            Enter manually
        </router-link>
    </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { scanReceiptImages } from '../stores/receipts';
import { ensureUsageStatus, usageState } from '../stores/usage';
import { showUpgrade } from '../stores/upgrade';

const router = useRouter();

const libraryInput = ref(null);
const photos = ref([]);
const uploading = ref(false);
const processing = ref(false);
const error = ref('');

const maxPhotos = 7;
const allowedTypes = ['image/jpeg', 'image/png', 'image/heic', 'image/heif'];
const allowedExtensions = ['jpg', 'jpeg', 'png', 'heic', 'heif'];
const maxUploadBytes = 1.2 * 1024 * 1024;
const maxTotalBytes = 7.5 * 1024 * 1024;
const maxDimension = 2200;
const receiptUsage = computed(() => usageState.data?.features?.receipt_scans || null);
const receiptLimitReached = computed(() => !!receiptUsage.value?.exhausted);
const receiptRemainingText = computed(() => {
    if (usageState.plan === 'premium') return '';
    if (!receiptUsage.value || receiptUsage.value.limit === null) return '';
    return `${receiptUsage.value.remaining} of ${receiptUsage.value.limit} scans remaining this month`;
});
const openUpgrade = () => {
    showUpgrade(usageState.plan === 'starter' ? 'pro' : 'premium', 'receipt scanning');
};

onMounted(() => {
    ensureUsageStatus();
});

const getExtension = (fileName) => {
    if (!fileName) return '';
    const segments = fileName.split('.');
    return segments.length > 1 ? segments.pop().toLowerCase() : '';
};

const isAllowedFile = (file) => {
    const extension = getExtension(file?.name);
    return allowedTypes.includes(file?.type) || allowedExtensions.includes(extension);
};


const clearPhotos = () => {
    photos.value.forEach((photo) => URL.revokeObjectURL(photo.url));
    photos.value = [];
    error.value = '';
};

const removePhoto = (index) => {
    const [removed] = photos.value.splice(index, 1);
    if (removed?.url) {
        URL.revokeObjectURL(removed.url);
    }
};

const canvasToBlob = (canvas, type, quality) =>
    new Promise((resolve) => {
        canvas.toBlob((blob) => resolve(blob), type, quality);
    });

const loadImage = (file) =>
    new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
            URL.revokeObjectURL(url);
            resolve(img);
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('load failed'));
        };
        img.src = url;
    });

const isHeic = (file) => {
    const extension = getExtension(file?.name);
    return extension === 'heic' || extension === 'heif' || String(file?.type).includes('heic') || String(file?.type).includes('heif');
};

const prepareUploadFile = async (file) => {
    if (!file) return file;

    const shouldConvert = isHeic(file) || file.size > maxUploadBytes;
    if (!shouldConvert) {
        return file;
    }

    try {
        const img = await loadImage(file);
        const ratio = Math.min(1, maxDimension / Math.max(img.width || 1, img.height || 1));
        const targetWidth = Math.max(1, Math.round(img.width * ratio));
        const targetHeight = Math.max(1, Math.round(img.height * ratio));

        const canvas = document.createElement('canvas');
        canvas.width = targetWidth;
        canvas.height = targetHeight;
        const ctx = canvas.getContext('2d');
        if (!ctx) return file;

        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, targetWidth, targetHeight);
        ctx.drawImage(img, 0, 0, targetWidth, targetHeight);

        let quality = 0.88;
        let blob = await canvasToBlob(canvas, 'image/jpeg', quality);

        while (blob && blob.size > maxUploadBytes && quality > 0.6) {
            quality -= 0.08;
            blob = await canvasToBlob(canvas, 'image/jpeg', quality);
        }

        if (!blob) return file;

        const outputName = file.name?.replace(/\.\w+$/, '.jpg') || 'receipt.jpg';
        return new File([blob], outputName, { type: 'image/jpeg', lastModified: Date.now() });
    } catch (err) {
        return file;
    }
};

const addFiles = async (files) => {
    if (!files?.length) return;
    const newPhotos = [];
    processing.value = true;

    let totalBytes = photos.value.reduce((sum, photo) => sum + (photo.file?.size || 0), 0);

    for (const file of Array.from(files)) {
        if (photos.value.length + newPhotos.length >= maxPhotos) {
            error.value = 'You can add up to 7 photos.';
            break;
        }
        if (!isAllowedFile(file)) {
            error.value = 'Please choose JPG, PNG, or HEIC files.';
            continue;
        }
        const uploadFile = await prepareUploadFile(file);
        if (uploadFile.size > maxUploadBytes) {
            error.value = 'That photo is too large right now. Try a smaller file or a screenshot.';
            continue;
        }
        if (totalBytes + uploadFile.size > maxTotalBytes) {
            error.value = 'That’s a lot of data for one upload. Try fewer photos or smaller files.';
            continue;
        }
        newPhotos.push({
            id: `${file.name}-${file.size}-${file.lastModified}`,
            file: uploadFile,
            url: URL.createObjectURL(file),
        });
        totalBytes += uploadFile.size;
    }

    if (newPhotos.length) {
        error.value = '';
        photos.value = [...photos.value, ...newPhotos];
    }
    processing.value = false;
};

const handleFile = async (event) => {
    await addFiles(event.target.files);
    event.target.value = '';
};

const usePhotos = async () => {
    if (!photos.value.length || receiptLimitReached.value) return;
    uploading.value = true;
    error.value = '';

    try {
        const receipt = await scanReceiptImages(photos.value.map((photo) => photo.file));
        await ensureUsageStatus(true);
        router.push({ name: 'receipts-review', params: { id: receipt.id } });
    } catch (err) {
        error.value =
            err?.response?.data?.errors?.image?.[0] ||
            err?.response?.data?.errors?.images?.[0] ||
            err?.response?.data?.errors?.['images.0']?.[0] ||
            err?.response?.data?.message ||
            'We could not read that. You can try again or enter it manually.';
        if (err?.response?.status === 429) {
            await ensureUsageStatus(true);
        }
    } finally {
        uploading.value = false;
    }
};

onBeforeUnmount(() => {
    photos.value.forEach((photo) => URL.revokeObjectURL(photo.url));
});
</script>
