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
                    Choose statement photos
                </router-link>
            </div>
            <p class="muted">
                Choose statement screenshots (PNG/JPG/WebP). You can select multiple screenshots at once.
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
import { ref } from 'vue';

const error = ref('');
const uploading = ref(false);
</script>
