<template>
    <section :class="['scan-screen', { scrollable: !!capturedUrl }]">
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
                <video
                    v-else-if="cameraActive"
                    ref="videoRef"
                    autoplay
                    playsinline
                    muted
                ></video>
                <div v-if="!capturedUrl" class="camera-overlay">
                    <div class="camera-frame"></div>
                    <p class="camera-hint">
                        {{ cameraActive ? 'Tap Capture photo to save this frame.' : 'Line up the receipt and tap Take photo.' }}
                    </p>
                </div>
            </div>
        </div>

        <div v-if="!capturedUrl" class="scan-actions">
            <button class="primary-button" type="button" :disabled="starting || uploading" @click="handleTakePhoto">
                {{ cameraActive ? 'Capture photo' : 'Take photo' }}
            </button>
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
import { nextTick, onBeforeUnmount, ref } from 'vue';
import { useRouter } from 'vue-router';
import { scanReceipt } from '../stores/receipts';

const router = useRouter();

const libraryInput = ref(null);
const capturedUrl = ref('');
const capturedBlob = ref(null);
const uploading = ref(false);
const error = ref('');
const videoRef = ref(null);
const streamRef = ref(null);
const cameraActive = ref(false);
const starting = ref(false);

const stopCamera = () => {
    if (streamRef.value) {
        streamRef.value.getTracks().forEach((track) => track.stop());
        streamRef.value = null;
    }
    cameraActive.value = false;
};

const startCamera = async () => {
    if (cameraActive.value || starting.value) return;
    error.value = '';
    starting.value = true;

    try {
        if (!navigator.mediaDevices?.getUserMedia) {
            error.value = 'Camera access is unavailable. You can choose a photo instead.';
            return;
        }

        const stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: 'environment' } },
            audio: false,
        });

        streamRef.value = stream;
        cameraActive.value = true;
        await nextTick();

        if (!videoRef.value) {
            throw new Error('camera_view_missing');
        }

        videoRef.value.srcObject = stream;
        await new Promise((resolve) => {
            videoRef.value.onloadedmetadata = () => resolve();
        });
        await videoRef.value.play();
    } catch (err) {
        error.value = 'We could not access the camera. You can choose a photo instead.';
        stopCamera();
    } finally {
        starting.value = false;
    }
};

const retakePhoto = () => {
    if (capturedUrl.value) {
        URL.revokeObjectURL(capturedUrl.value);
    }
    capturedUrl.value = '';
    capturedBlob.value = null;
    startCamera();
};

const handleFile = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    if (capturedUrl.value) {
        URL.revokeObjectURL(capturedUrl.value);
    }
    stopCamera();
    capturedBlob.value = file;
    capturedUrl.value = URL.createObjectURL(file);
    event.target.value = '';
};

const capturePhoto = async () => {
    if (!videoRef.value) return;
    const width = videoRef.value.videoWidth;
    const height = videoRef.value.videoHeight;
    if (!width || !height) return;

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d');
    if (!context) return;
    context.drawImage(videoRef.value, 0, 0, width, height);

    const blob = await new Promise((resolve) => {
        canvas.toBlob(resolve, 'image/jpeg', 0.9);
    });

    if (!blob) {
        error.value = 'We could not capture that. You can try again.';
        return;
    }

    if (capturedUrl.value) {
        URL.revokeObjectURL(capturedUrl.value);
    }

    capturedBlob.value = blob;
    capturedUrl.value = URL.createObjectURL(blob);
    stopCamera();
};

const handleTakePhoto = async () => {
    if (!cameraActive.value) {
        await startCamera();
        return;
    }

    await capturePhoto();
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
    stopCamera();
});
</script>
