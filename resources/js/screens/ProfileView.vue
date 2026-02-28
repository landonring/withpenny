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
            <div class="card-title">Life Phase</div>
            <p class="card-sub">This helps Penny tailor reflections to your current stage.</p>
            <div class="life-phase-grid life-phase-grid--profile">
                <button
                    v-for="phase in lifePhases"
                    :key="phase.value"
                    class="life-phase-card"
                    :class="{ active: lifePhaseSelection === phase.value }"
                    type="button"
                    @click="lifePhaseSelection = phase.value"
                >
                    <div class="life-phase-head">
                        <span class="life-phase-name">{{ phase.title }}</span>
                        <span class="life-phase-range">{{ phase.range }}</span>
                    </div>
                    <p class="life-phase-description">{{ phase.description }}</p>
                </button>
            </div>
            <p v-if="lifePhaseError" class="form-error">{{ lifePhaseError }}</p>
            <p v-if="lifePhaseSuccess" class="muted">{{ lifePhaseSuccess }}</p>
            <div class="journey-actions">
                <button
                    class="primary-button"
                    type="button"
                    :disabled="lifePhaseSaving || !lifePhaseSelection || !lifePhaseChanged"
                    @click="handleLifePhaseSave"
                >
                    {{ lifePhaseSaving ? 'Saving…' : 'Save life phase' }}
                </button>
            </div>
        </div>

        <div class="card">
            <div class="card-title">Passkeys</div>
            <p class="card-sub">Passkeys let you log in quickly using your device.</p>
            <div class="journey-actions">
                <button
                    v-if="biometricsSupported"
                    class="primary-button"
                    type="button"
                    :disabled="biometricBusy"
                    @click="handleEnableBiometrics"
                >
                    {{ biometricBusy ? 'Adding…' : 'Add passkey' }}
                </button>
                <button
                    v-if="biometricsSupported && biometricsEnabled"
                    class="ghost-button"
                    type="button"
                    :disabled="biometricBusy"
                    @click="handleDisableBiometrics"
                >
                    {{ biometricBusy ? 'Updating…' : 'Remove passkeys' }}
                </button>
                <span v-else class="muted">Passkeys aren’t available on this device.</span>
            </div>
            <p v-if="biometricMessage" class="muted">{{ biometricMessage }}</p>
        </div>

        <div class="card">
            <div class="card-title">Plan</div>
            <p class="card-sub">Adjust your plan anytime.</p>
            <div class="data-summary">
                <div class="detail-row">
                    <span>Current plan</span>
                    <span>{{ currentPlanLabel }}</span>
                </div>
                <div class="detail-row">
                    <span>Current cost</span>
                    <span>{{ currentCostLabel }}</span>
                </div>
                <div class="detail-row">
                    <span>Billing</span>
                    <span>{{ billingIntervalLabel }}</span>
                </div>
                <div class="detail-row">
                    <span>Status</span>
                    <span>{{ billingStatusLabel }}</span>
                </div>
                <div v-if="billingStatus?.pending_change" class="detail-row">
                    <span>Scheduled update</span>
                    <span>{{ pendingChangeLabel }}</span>
                </div>
            </div>
            <div class="plan-section">
                <div class="plan-toggle type-toggle" role="group" aria-label="Billing interval">
                    <button
                        class="toggle-button"
                        :class="{ active: billingInterval === 'monthly' }"
                        type="button"
                        @click="() => { billingInterval = 'monthly'; intervalOverride = true; }"
                    >
                        Monthly
                    </button>
                    <button
                        class="toggle-button"
                        :class="{ active: billingInterval === 'yearly' }"
                        type="button"
                        @click="() => { billingInterval = 'yearly'; intervalOverride = true; }"
                    >
                        Yearly
                    </button>
                </div>
                <div class="plan-options type-toggle" role="group" aria-label="Plan selection">
                    <button
                        class="toggle-button"
                        :class="{ active: billingStatus?.plan === 'starter' }"
                        type="button"
                        :disabled="billingBusy"
                        @click="handlePlanChange('starter')"
                    >
                        Starter
                    </button>
                    <button
                        class="toggle-button"
                        :class="{ active: billingStatus?.plan === 'pro' }"
                        type="button"
                        :disabled="billingBusy"
                        @click="handlePlanChange('pro')"
                    >
                        Pro
                    </button>
                    <button
                        class="toggle-button"
                        :class="{ active: billingStatus?.plan === 'premium' }"
                        type="button"
                        :disabled="billingBusy"
                        @click="handlePlanChange('premium')"
                    >
                        Premium
                    </button>
                </div>
            </div>
            <div class="journey-actions">
                <button class="ghost-button" type="button" :disabled="billingBusy" @click="handleManageBilling">
                    {{ billingBusy ? 'Opening…' : 'Manage billing' }}
                </button>
            </div>
            <p v-if="pendingChangeMessage" class="muted">{{ pendingChangeMessage }}</p>
            <p v-if="displayBillingMessage" class="muted">{{ displayBillingMessage }}</p>
        </div>

        <div class="card">
            <div class="card-title">Account actions</div>
            <p class="card-sub">You can sign out anytime. If you delete your account, your data is removed.</p>
            <div class="account-actions">
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
                <button class="ghost-button" type="button" :disabled="clearingAll" @click="handleDeleteAll">
                    {{ clearingAll ? 'Clearing…' : 'Delete all transactions & income' }}
                </button>
                <button class="ghost-button" type="button" :disabled="replayingOnboarding" @click="handleReplayOnboarding">
                    {{ replayingOnboarding ? 'Starting…' : 'Replay guided onboarding' }}
                </button>
            </div>
            <p v-if="dataMessage" class="muted">{{ dataMessage }}</p>
        </div>

    </section>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { authState, deleteAccount, deleteAllTransactions, deleteImportedTransactions, fetchDataSummary, logout, updateLifePhase, updateProfile } from '../stores/auth';
import { checkBiometricSupport, disableBiometrics, enableBiometrics, refreshBiometricStatus } from '../stores/biometrics';
import { applyBillingStatus, cancelSubscription, fetchBillingStatus, openBillingPortal, resumeSubscription, startCheckout } from '../stores/billing';
import { lifePhases } from '../data/lifePhases';
import { replayOnboarding } from '../stores/onboarding';

const router = useRouter();
const route = useRoute();

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
const clearingAll = ref(false);
const biometricBusy = ref(false);
const biometricMessage = ref('');
const billingStatus = ref(null);
const billingInterval = ref('monthly');
const intervalOverride = ref(false);
const billingBusy = ref(false);
const billingMessage = ref('');
const lifePhaseSelection = ref(authState.user?.life_phase || '');
const lifePhaseSaving = ref(false);
const lifePhaseError = ref('');
const lifePhaseSuccess = ref('');
const replayingOnboarding = ref(false);

const planLabels = {
    starter: 'Starter',
    pro: 'Pro',
    premium: 'Premium',
};

const currentPlanLabel = computed(() => planLabels[billingStatus.value?.plan] || 'Starter');
const currentCostLabel = computed(() => {
    const plan = billingStatus.value?.plan || 'starter';
    const interval = billingStatus.value?.interval === 'yearly' ? 'yearly' : 'monthly';
    if (plan === 'starter') return '$0 / month';
    const base = plan === 'premium' ? 25 : 15;
    if (interval === 'monthly') return `$${base} / month`;
    const yearly = Math.round(base * 12 * 0.9);
    return `$${yearly} / year`;
});
const billingIntervalLabel = computed(() => (billingStatus.value?.interval === 'yearly' ? 'Yearly' : 'Monthly'));
const billingStatusLabel = computed(() => {
    if (!billingStatus.value) return '—';
    if (billingStatus.value.pending_change) return 'Scheduled change';
    if (billingStatus.value.active) return 'Active';
    if (billingStatus.value.status === 'canceled' || billingStatus.value.status === 'cancelled') return 'Ending';
    return 'Inactive';
});
const pendingChangeLabel = computed(() => {
    const pending = billingStatus.value?.pending_change;
    if (!pending) return '';

    const plan = planLabels[pending.plan] || 'Starter';
    const date = pending.effective_at ? new Date(pending.effective_at).toLocaleDateString() : '';
    return date ? `${plan} on ${date}` : plan;
});
const pendingChangeMessage = computed(() => {
    const pending = billingStatus.value?.pending_change;
    if (!pending) return '';
    if (pending.message) return pending.message;
    if (pending.effective_at) {
        return `Your plan will adjust on ${new Date(pending.effective_at).toLocaleDateString()}.`;
    }
    return 'Your plan will adjust at the end of the billing period.';
});
const displayBillingMessage = computed(() => {
    const billing = String(billingMessage.value || '').trim();
    const pending = String(pendingChangeMessage.value || '').trim();
    if (!billing) return '';
    if (pending && billing === pending) return '';
    return billing;
});
const lifePhaseChanged = computed(() => (lifePhaseSelection.value || '') !== (authState.user?.life_phase || ''));

const biometricsSupported = ref(false);
const biometricsEnabled = ref(false);

watch(
    () => authState.user,
    (user) => {
        if (user?.email) {
            form.value.email = user.email;
        }
        lifePhaseSelection.value = user?.life_phase || '';
    }
);

watch(
    () => lifePhaseSelection.value,
    () => {
        lifePhaseSuccess.value = '';
        lifePhaseError.value = '';
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

const handleLifePhaseSave = async () => {
    lifePhaseSaving.value = true;
    lifePhaseError.value = '';
    lifePhaseSuccess.value = '';

    try {
        await updateLifePhase({ life_phase: lifePhaseSelection.value });
        lifePhaseSuccess.value = 'Saved.';
    } catch (err) {
        lifePhaseError.value = err?.response?.data?.message || 'Unable to update life phase right now.';
    } finally {
        lifePhaseSaving.value = false;
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

const handleReplayOnboarding = async () => {
    replayingOnboarding.value = true;
    dataMessage.value = '';
    try {
        await replayOnboarding();
        router.push('/app');
    } catch (err) {
        dataMessage.value = err?.response?.data?.message || 'Unable to start onboarding right now.';
    } finally {
        replayingOnboarding.value = false;
    }
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

const clearLocalTransactions = () => {
    const userId = authState.user?.id;
    if (!userId) return;
    const prefixes = [
        `penny.transactions.${userId}.`,
        `penny.income.${userId}.`,
        `penny.future.${userId}.`,
        `penny.balance.${userId}.`,
        `penny.queue.${userId}`,
        `penny.month.${userId}`,
    ];
    Object.keys(localStorage).forEach((key) => {
        if (prefixes.some((prefix) => key.startsWith(prefix))) {
            localStorage.removeItem(key);
        }
    });
};

const handleDeleteAll = async () => {
    if (!window.confirm('Delete all transactions and income? This cannot be undone.')) {
        return;
    }
    clearingAll.value = true;
    dataMessage.value = '';

    try {
        const result = await deleteAllTransactions();
        clearLocalTransactions();
        dataMessage.value = `Removed ${result.deleted || 0} transactions.`;
        await loadDataSummary();
    } catch (err) {
        dataMessage.value = 'Unable to remove transactions right now.';
    } finally {
        clearingAll.value = false;
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
        biometricMessage.value = 'Passkey is ready for next time.';
    } catch (err) {
        biometricMessage.value = err?.response?.data?.message || err?.message || 'Unable to add a passkey right now.';
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
        biometricMessage.value = 'Passkeys are off.';
    } catch (err) {
        biometricMessage.value = err?.response?.data?.message || err?.message || 'Unable to update passkeys right now.';
    } finally {
        biometricBusy.value = false;
    }
};

const loadBilling = async () => {
    try {
        billingStatus.value = await fetchBillingStatus();
        applyBillingStatus(billingStatus.value, authState.user?.id || null);
        if (billingStatus.value?.interval && !intervalOverride.value) {
            billingInterval.value = billingStatus.value.interval;
        }
    } catch {
        billingStatus.value = {
            plan: 'starter',
            base_plan: 'starter',
            effective_plan: 'starter',
            interval: 'monthly',
            status: 'none',
            active: false,
            pending_change: null,
        };
        applyBillingStatus(billingStatus.value, authState.user?.id || null);
    }
};

const handlePlanChange = async (plan) => {
    if (billingBusy.value) return;
    billingMessage.value = '';
    billingBusy.value = true;

    try {
        await loadBilling();
        const pending = billingStatus.value?.pending_change || null;

        if (billingStatus.value?.base_plan === 'premium' && plan === 'pro') {
            billingMessage.value = 'unable to dongrade to pro, please downgrade to free and than upgrade to pro';
            return;
        }

        if (plan === 'starter') {
            if (pending?.type === 'cancel') {
                billingMessage.value = pending.message || 'Your plan will adjust at the end of the billing period.';
                return;
            }
            if (!billingStatus.value || billingStatus.value.base_plan === 'starter') {
                billingMessage.value = 'You are already on Starter.';
            } else {
                const data = await cancelSubscription();
                billingMessage.value = data?.message || 'Your plan will adjust at the end of the billing period.';
                await loadBilling();
            }
            return;
        }

        if (billingStatus.value?.base_plan === plan && !pending) {
            billingMessage.value = 'You are already on this plan.';
            return;
        }

        if (pending && (pending.type === 'cancel' || pending.type === 'downgrade') && billingStatus.value?.base_plan === plan) {
            await resumeSubscription();
            billingMessage.value = 'Cancellation removed. Your plan stays active.';
            await loadBilling();
            return;
        }

        const data = await startCheckout(plan, billingInterval.value);
        if (data?.url) {
            window.location.href = data.url;
            return;
        }
        if (data?.status === 'scheduled_downgrade') {
            billingMessage.value = data?.message || 'Your plan will adjust at the end of the billing period.';
            await loadBilling();
            return;
        }
        if (data?.status === 'resumed') {
            billingMessage.value = data?.message || 'Cancellation removed. Your plan stays active.';
            await loadBilling();
            return;
        }
        if (data?.status === 'swapped') {
            billingMessage.value = 'Plan updated.';
            await loadBilling();
            return;
        }
        if (data?.status === 'already_subscribed') {
            billingMessage.value = 'You are already on this plan.';
            await loadBilling();
            return;
        }
    } catch (err) {
        billingMessage.value = err?.response?.data?.message || 'Unable to update plan right now.';
    } finally {
        billingBusy.value = false;
    }
};

const handleManageBilling = async () => {
    billingMessage.value = '';
    billingBusy.value = true;

    try {
        const data = await openBillingPortal();
        if (data?.url) {
            window.location.href = data.url;
            return;
        }
    } catch (err) {
        billingMessage.value = 'Unable to open billing settings right now.';
    } finally {
        billingBusy.value = false;
    }
};

const handleVisibilityRefresh = () => {
    if (document.visibilityState === 'visible') {
        loadBilling();
    }
};

const scrollToTop = () => {
    const scroller = document.querySelector('.main-content');
    if (scroller) {
        scroller.scrollTo({ top: 0, left: 0, behavior: 'auto' });
        return;
    }
    window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
};

onMounted(() => {
    scrollToTop();
    loadDataSummary();
    loadBiometrics();
    loadBilling();
    if (typeof window !== 'undefined') {
        window.addEventListener('focus', loadBilling);
        document.addEventListener('visibilitychange', handleVisibilityRefresh);
    }
    if (route.query.billing) {
        loadBilling();
    }
});

onUnmounted(() => {
    if (typeof window !== 'undefined') {
        window.removeEventListener('focus', loadBilling);
        document.removeEventListener('visibilitychange', handleVisibilityRefresh);
    }
});

</script>
