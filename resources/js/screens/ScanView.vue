<template>
    <section class="scan-screen">
        <div class="scan-header">
            <div>
                <p class="eyebrow">Scan</p>
                <h1 class="screen-title">Take a photo of your receipt</h1>
                <p class="card-sub">We'll help pull out the details after you confirm the photo.</p>
            </div>
        </div>

        <div class="scan-stage">
            <div class="camera-view">
                <img v-if="capturedUrl" :src="capturedUrl" alt="Receipt preview" />
                <div v-else class="camera-overlay">
                    <div class="camera-frame"></div>
                    <p class="camera-hint">Line up the receipt and tap Take photo.</p>
                </div>
            </div>
        </div>

        <div v-if="!capturedUrl" class="scan-actions">
            <label class="primary-button scan-choice">
                Take photo
                <input
                    ref="cameraInput"
                    type="file"
                    accept="image/*"
                    capture="environment"
                    @change="handleFile"
                />
            </label>
            <label class="ghost-button scan-choice">
                Choose from photos
                <input
                    ref="libraryInput"
                    type="file"
                    accept="image/*"
                    @change="handleFile"
                />
            </label>
        </div>

        <div v-else class="scan-actions">
            <button class="primary-button" type="button" :disabled="uploading" @click="usePhoto">
                {{ uploading ? 'Preparing…' : 'Use this photo' }}
            </button>
            <button class="ghost-button" type="button" :disabled="uploading" @click="retakePhoto">
                Retake
            </button>
        </div>

        <p v-if="error" class="form-error">{{ error }}</p>
        <router-link v-if="error" class="ghost-button" :to="{ name: 'transactions-new' }">
            Enter manually
        </router-link>
    </section>
</template>

<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { useRouter } from 'vue-router';
import { scanReceipt } from '../stores/receipts';

const router = useRouter();

const cameraInput = ref(null);
const libraryInput = ref(null);
const capturedUrl = ref('');
const capturedBlob = ref(null);
const uploading = ref(false);
const error = ref('');

const retakePhoto = () => {
    if (capturedUrl.value) {
        URL.revokeObjectURL(capturedUrl.value);
    }
    capturedUrl.value = '';
    capturedBlob.value = null;
};

const handleFile = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    if (capturedUrl.value) {
        URL.revokeObjectURL(capturedUrl.value);
    }
    capturedBlob.value = file;
    capturedUrl.value = URL.createObjectURL(file);
    event.target.value = '';
};

const usePhoto = async () => {
    if (!capturedBlob.value) return;
    uploading.value = true;
    error.value = '';

    try {
        const receipt = await scanReceipt(capturedBlob.value);
        router.push({ name: 'receipts-review', params: { id: receipt.id } });
    } catch (err) {
        error.value = 'We could not read that. You can try again or enter it manually.';
    } finally {
        uploading.value = false;
    }
};

onBeforeUnmount(() => {
    if (capturedUrl.value) {
        URL.revokeObjectURL(capturedUrl.value);
    }
});
</script>
