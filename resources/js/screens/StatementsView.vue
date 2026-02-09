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
                Uploading a statement can save time. You're free to keep things manual if you prefer.
            </p>

            <div class="statement-entry-actions">
                <router-link class="primary-button" :to="{ name: 'statements-scan' }">
                    Scan statement
                </router-link>
                <label class="ghost-button file-button" :class="{ disabled: uploading }">
                    {{ uploading ? 'Preparing…' : 'Upload file' }}
                    <input
                        ref="fileInput"
                        type="file"
                        accept=".csv,.pdf"
                        :disabled="uploading"
                        @change="handleFile"
                    />
                </label>
            </div>
            <p class="muted">Scan with your camera, or upload CSV/PDF. Files are deleted after parsing.</p>

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
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { uploadStatement } from '../stores/statements';

const router = useRouter();
const fileInput = ref(null);
const error = ref('');
const uploading = ref(false);

const handleFile = async (event) => {
    const file = event.target.files?.[0];
    if (!file) return;

    error.value = '';
    uploading.value = true;

    try {
        const importData = await uploadStatement(file);
        router.push({ name: 'statements-review', params: { id: importData.id } });
    } catch (err) {
        error.value = err?.response?.data?.message || "We had trouble reading that file. You can try again or keep things manual.";
    } finally {
        uploading.value = false;
        event.target.value = '';
    }
};
</script>
