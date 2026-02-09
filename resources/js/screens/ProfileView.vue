<template>
    <section class="screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">Profile</p>
                <h1 class="screen-title">Your settings</h1>
            </div>
            <div class="accent-chip">Account</div>
        </div>

        <div class="card">
            <p class="card-sub">Update your email or password whenever you need.</p>
            <form class="auth-form" @submit.prevent="handleSubmit">
                <label class="field">
                    <span>Email</span>
                    <input v-model="form.email" type="email" autocomplete="email" required />
                </label>

                <label class="field">
                    <span>Current password</span>
                    <div class="field-row">
                        <input
                            v-model="form.current_password"
                            :type="showCurrent ? 'text' : 'password'"
                            autocomplete="current-password"
                        />
                        <button class="field-toggle" type="button" @click="showCurrent = !showCurrent">
                            {{ showCurrent ? 'Hide' : 'Show' }}
                        </button>
                    </div>
                </label>

                <label class="field">
                    <span>New password</span>
                    <div class="field-row">
                        <input
                            v-model="form.password"
                            :type="showNew ? 'text' : 'password'"
                            autocomplete="new-password"
                            minlength="8"
                        />
                        <button class="field-toggle" type="button" @click="showNew = !showNew">
                            {{ showNew ? 'Hide' : 'Show' }}
                        </button>
                    </div>
                </label>

                <label class="field">
                    <span>Confirm new password</span>
                    <div class="field-row">
                        <input
                            v-model="form.password_confirmation"
                            :type="showConfirm ? 'text' : 'password'"
                            autocomplete="new-password"
                            minlength="8"
                        />
                        <button class="field-toggle" type="button" @click="showConfirm = !showConfirm">
                            {{ showConfirm ? 'Hide' : 'Show' }}
                        </button>
                    </div>
                </label>

                <p v-if="error" class="form-error">{{ error }}</p>
                <p v-if="success" class="muted">{{ success }}</p>

                <button class="primary-button" type="submit" :disabled="loading">
                    {{ loading ? 'Saving…' : 'Save changes' }}
                </button>
            </form>
        </div>

        <div class="card">
            <div class="card-title">Security</div>
            <p class="card-sub">Face ID lets you log in quickly using your device.</p>
            <div class="journey-actions">
                <button
                    v-if="biometricsSupported && !biometricsEnabled"
                    class="primary-button"
                    type="button"
                    :disabled="biometricBusy"
                    @click="handleEnableBiometrics"
                >
                    {{ biometricBusy ? 'Enabling…' : 'Enable Face ID' }}
                </button>
                <button
                    v-else-if="biometricsSupported && biometricsEnabled"
                    class="ghost-button"
                    type="button"
                    :disabled="biometricBusy"
                    @click="handleDisableBiometrics"
                >
                    {{ biometricBusy ? 'Updating…' : 'Disable Face ID' }}
                </button>
                <span v-else class="muted">Face ID isn’t available on this device.</span>
            </div>
            <p v-if="biometricMessage" class="muted">{{ biometricMessage }}</p>
        </div>

        <div class="card">
            <div class="card-title">Account actions</div>
            <p class="card-sub">You can sign out anytime. If you delete your account, your data is removed.</p>
            <div class="journey-actions">
                <button class="ghost-button" type="button" @click="handleLogout">
                    Log out
                </button>
                <button class="danger-button" type="button" @click="confirmingDelete = true">
                    Delete account
                </button>
            </div>

            <div v-if="confirmingDelete" class="delete-confirm">
                <p class="card-sub">
                    Are you sure? This removes your account and saved data. You can always create a new account later.
                </p>
                <div class="journey-actions">
                    <button class="ghost-button" type="button" @click="confirmingDelete = false">
                        Cancel
                    </button>
                    <button class="danger-button" type="button" :disabled="deleting" @click="handleDelete">
                        {{ deleting ? 'Deleting…' : 'Confirm delete' }}
                    </button>
                </div>
                <p v-if="deleteError" class="form-error">{{ deleteError }}</p>
            </div>
        </div>

        <div class="card">
            <div class="card-title">Data & privacy</div>
            <p class="card-sub">Penny only keeps what is needed to help you understand your money.</p>
            <div v-if="dataSummary" class="data-summary">
                <div class="detail-row">
                    <span>Total transactions</span>
                    <span>{{ dataSummary.transactions_total }}</span>
                </div>
                <div class="detail-row">
                    <span>Imported transactions</span>
                    <span>{{ dataSummary.transactions_imported }}</span>
                </div>
                <div class="detail-row">
                    <span>Savings journeys</span>
                    <span>{{ dataSummary.journeys }}</span>
                </div>
                <div class="detail-row">
                    <span>Receipts</span>
                    <span>{{ dataSummary.receipts }}</span>
                </div>
                <div class="detail-row">
                    <span>Statement imports pending</span>
                    <span>{{ dataSummary.statement_imports_pending }}</span>
                </div>
            </div>
            <div class="journey-actions">
                <button class="ghost-button" type="button" @click="clearChat">
                    Clear chat history
                </button>
                <button class="ghost-button" type="button" :disabled="clearingImported" @click="handleDeleteImported">
                    {{ clearingImported ? 'Clearing…' : 'Delete imported transactions' }}
                </button>
            </div>
            <p v-if="dataMessage" class="muted">{{ dataMessage }}</p>
        </div>

    </section>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { authState, deleteAccount, deleteImportedTransactions, fetchDataSummary, logout, updateProfile } from '../stores/auth';
import { checkBiometricSupport, disableBiometrics, enableBiometrics, refreshBiometricStatus } from '../stores/biometrics';

const router = useRouter();

const form = ref({
    email: authState.user?.email || '',
    current_password: '',
    password: '',
    password_confirmation: '',
});

const showCurrent = ref(false);
const showNew = ref(false);
const showConfirm = ref(false);
const loading = ref(false);
const error = ref('');
const success = ref('');
const confirmingDelete = ref(false);
const deleting = ref(false);
const deleteError = ref('');
const dataSummary = ref(null);
const dataMessage = ref('');
const clearingImported = ref(false);
const biometricBusy = ref(false);
const biometricMessage = ref('');

const biometricsSupported = ref(false);
const biometricsEnabled = ref(false);

watch(
    () => authState.user,
    (user) => {
        if (user?.email) {
            form.value.email = user.email;
        }
    }
);

const handleSubmit = async () => {
    loading.value = true;
    error.value = '';
    success.value = '';

    try {
        await updateProfile(form.value);
        form.value.current_password = '';
        form.value.password = '';
        form.value.password_confirmation = '';
        success.value = 'Saved.';
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to save changes right now.';
    } finally {
        loading.value = false;
    }
};

const handleLogout = async () => {
    await logout();
    router.push({ name: 'login' });
};

const handleDelete = async () => {
    deleting.value = true;
    deleteError.value = '';

    try {
        await deleteAccount();
        router.push({ name: 'register' });
    } catch (err) {
        deleteError.value = err?.response?.data?.message || 'Unable to delete right now.';
    } finally {
        deleting.value = false;
    }
};

const loadDataSummary = async () => {
    try {
        dataSummary.value = await fetchDataSummary();
    } catch {
        dataSummary.value = null;
    }
};

const clearChat = () => {
    localStorage.removeItem('penny.chat.messages');
    dataMessage.value = 'Chat history cleared.';
};

const handleDeleteImported = async () => {
    clearingImported.value = true;
    dataMessage.value = '';

    try {
        const result = await deleteImportedTransactions();
        dataMessage.value = `Removed ${result.deleted || 0} imported transactions.`;
        await loadDataSummary();
    } catch (err) {
        dataMessage.value = 'Unable to remove imported transactions right now.';
    } finally {
        clearingImported.value = false;
    }
};

const loadBiometrics = async () => {
    biometricsSupported.value = await checkBiometricSupport();
    if (biometricsSupported.value) {
        biometricsEnabled.value = await refreshBiometricStatus();
    }
};

const handleEnableBiometrics = async () => {
    biometricBusy.value = true;
    biometricMessage.value = '';

    try {
        await enableBiometrics();
        biometricsEnabled.value = true;
        biometricMessage.value = 'Face ID is ready for next time.';
    } catch (err) {
        biometricMessage.value = err?.response?.data?.message || err?.message || 'Unable to enable Face ID right now.';
    } finally {
        biometricBusy.value = false;
    }
};

const handleDisableBiometrics = async () => {
    biometricBusy.value = true;
    biometricMessage.value = '';

    try {
        await disableBiometrics();
        biometricsEnabled.value = false;
        biometricMessage.value = 'Face ID is off.';
    } catch (err) {
        biometricMessage.value = err?.response?.data?.message || err?.message || 'Unable to update Face ID right now.';
    } finally {
        biometricBusy.value = false;
    }
};

onMounted(() => {
    loadDataSummary();
    loadBiometrics();
});

</script>
