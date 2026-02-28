<template>
    <div class="app-shell">
        <teleport to="body">
            <div v-if="authState.impersonating" class="impersonation-banner">
                <span>Impersonating account.</span>
                <a class="impersonation-return" href="/admin/impersonate/stop">Return to admin</a>
            </div>
        </teleport>
        <main
            :class="[
                'main-content',
                {
                    'lock-scroll': route.meta.lockScroll || lifePhaseOpen || onboardingChoiceOpen,
                    'marketing-content': route.meta.marketing,
                    'full-bleed': route.meta.fullBleed,
                },
            ]"
        >
            <router-view />
        </main>

        <BottomNav v-if="!route.meta.hideNav" />
        <GuidedTourOverlay v-if="showGuidedOverlay" />

        <div v-if="onboardingChoiceOpen" class="upgrade-backdrop" role="dialog" aria-modal="true">
            <div class="upgrade-modal">
                <p class="upgrade-title">Guided onboarding</p>
                <p class="upgrade-copy">
                    Do you want a guided walkthrough of Penny’s core features now?
                </p>
                <p v-if="onboardingChoiceError" class="form-error">{{ onboardingChoiceError }}</p>
                <div class="upgrade-actions">
                    <button class="primary-button" type="button" :disabled="onboardingChoiceBusy" @click="handleStartGuidedTour">
                        {{ onboardingChoiceBusy ? 'Starting…' : 'Start guided tour' }}
                    </button>
                    <button class="ghost-button" type="button" :disabled="onboardingChoiceBusy" @click="handleSkipGuidedTour">
                        Not now
                    </button>
                </div>
            </div>
        </div>

        <div v-if="upgradePrompt.open" class="upgrade-backdrop" role="dialog" aria-modal="true">
            <div class="upgrade-modal">
                <p class="upgrade-title">A little more clarity.</p>
                <p class="upgrade-copy">
                    This feature is available on {{ upgradePlanLabel }}. Penny keeps things simple, but sometimes a little guidance goes a long way.
                </p>
                <p v-if="upgradeError" class="form-error">{{ upgradeError }}</p>
                <div class="upgrade-actions">
                    <button class="primary-button" type="button" :disabled="upgradeBusy" @click="handleUpgrade">
                        {{ upgradeBusy ? 'Starting…' : 'Upgrade' }}
                    </button>
                    <button class="ghost-button" type="button" :disabled="upgradeBusy" @click="handleCloseUpgrade">
                        Not now
                    </button>
                </div>
            </div>
        </div>

        <div v-if="lifePhaseOpen" class="life-phase-backdrop" role="dialog" aria-modal="true">
            <div class="life-phase-modal">
                <div>
                    <p class="eyebrow">Life Phase</p>
                    <h2 class="life-phase-title">Where are you in your money journey?</h2>
                    <p class="card-sub">This helps Penny tailor reflections to your current stage.</p>
                </div>
                <div class="life-phase-grid">
                    <button
                        v-for="phase in lifePhases"
                        :key="phase.value"
                        class="life-phase-card"
                        :class="{ active: selectedLifePhase === phase.value }"
                        type="button"
                        @click="selectedLifePhase = phase.value"
                    >
                        <div class="life-phase-head">
                            <span class="life-phase-name">{{ phase.title }}</span>
                            <span class="life-phase-range">{{ phase.range }}</span>
                        </div>
                        <p class="life-phase-description">{{ phase.description }}</p>
                    </button>
                </div>
                <p v-if="lifePhaseError" class="form-error">{{ lifePhaseError }}</p>
                <div class="life-phase-actions">
                    <button
                        class="primary-button"
                        type="button"
                        :disabled="lifePhaseBusy || !selectedLifePhase"
                        @click="handleLifePhaseSave"
                    >
                        {{ lifePhaseBusy ? 'Saving…' : 'Save' }}
                    </button>
                    <button class="ghost-button" type="button" :disabled="lifePhaseBusy" @click="handleLifePhaseSkip">
                        Skip for now
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BottomNav from './BottomNav.vue';
import { authState, updateLifePhase } from '../stores/auth';
import GuidedTourOverlay from './GuidedTourOverlay.vue';
import { billingState, completeCheckout, ensureBillingStatus, startCheckout } from '../stores/billing';
import { hideUpgrade, upgradePrompt } from '../stores/upgrade';
import { lifePhases } from '../data/lifePhases';
import { ensureOnboardingStatus, onboardingState, routeAllowedDuringOnboarding, skipOnboarding } from '../stores/onboarding';

const route = useRoute();
const router = useRouter();
const upgradeBusy = ref(false);
const upgradeError = ref('');
const billingSyncBusy = ref(false);
const lifePhaseBusy = ref(false);
const lifePhaseError = ref('');
const lifePhaseDismissed = ref(false);
const selectedLifePhase = ref('');
const onboardingDecisionMade = ref(false);
const onboardingChoiceBusy = ref(false);
const onboardingChoiceError = ref('');
const onboardingChoiceOpen = computed(() =>
    !!authState.user
    && onboardingState.mode
    && onboardingState.step === 0
    && !route.path.startsWith('/admin')
    && route.meta.requiresAuth
    && !onboardingDecisionMade.value
);
const showGuidedOverlay = computed(() =>
    !!authState.user
    && onboardingState.mode
    && !route.path.startsWith('/admin')
    && route.meta.requiresAuth
    && !onboardingChoiceOpen.value
);

const upgradePlanLabel = computed(() => {
    if (upgradePrompt.plan === 'premium') return 'Premium';
    return 'Pro';
});

const handleCloseUpgrade = () => {
    upgradeError.value = '';
    hideUpgrade();
};

const handleUpgrade = async () => {
    if (upgradeBusy.value) return;
    upgradeError.value = '';
    upgradeBusy.value = true;

    try {
        if (!authState.user) {
            router.push({ name: 'login', query: { plan: upgradePrompt.plan, interval: 'monthly', redirect: route.fullPath } });
            hideUpgrade();
            return;
        }
        await ensureBillingStatus();
        const interval = billingState.interval === 'yearly' ? 'yearly' : 'monthly';
        const data = await startCheckout(upgradePrompt.plan, interval);
        if (data?.url) {
            window.location.href = data.url;
            return;
        }
        hideUpgrade();
    } catch (err) {
        upgradeError.value = err?.response?.data?.message || 'Unable to start billing right now.';
    } finally {
        upgradeBusy.value = false;
    }
};

const lifePhaseStorageKey = () => {
    const userId = authState.user?.id;
    if (userId) {
        return `penny.life_phase.dismissed.${userId}`;
    }
    return 'penny.life_phase.dismissed';
};

const onboardingDecisionStorageKey = () => {
    const userId = authState.user?.id;
    if (!userId) return null;
    return `penny.onboarding.decision.${userId}`;
};

const syncOnboardingDecision = () => {
    const key = onboardingDecisionStorageKey();
    if (!key || typeof window === 'undefined') {
        onboardingDecisionMade.value = false;
        return;
    }
    onboardingDecisionMade.value = localStorage.getItem(key) === '1';
};

const markOnboardingDecision = () => {
    const key = onboardingDecisionStorageKey();
    if (!key || typeof window === 'undefined') return;
    localStorage.setItem(key, '1');
    onboardingDecisionMade.value = true;
};

const syncLifePhaseDismissed = () => {
    if (typeof window === 'undefined') return;
    if (!authState.user) {
        lifePhaseDismissed.value = false;
        return;
    }
    const key = lifePhaseStorageKey();
    lifePhaseDismissed.value = localStorage.getItem(key) === '1';
};

const lifePhaseOpen = computed(() => {
    if (!authState.ready || !authState.user) return false;
    if (onboardingState.mode) return false;
    if (authState.user.life_phase) return false;
    if (lifePhaseDismissed.value) return false;
    return true;
});

const handleLifePhaseSkip = () => {
    if (typeof window !== 'undefined') {
        localStorage.setItem(lifePhaseStorageKey(), '1');
    }
    lifePhaseDismissed.value = true;
    lifePhaseError.value = '';
};

const handleLifePhaseSave = async () => {
    if (!selectedLifePhase.value || lifePhaseBusy.value) return;
    lifePhaseBusy.value = true;
    lifePhaseError.value = '';
    try {
        await updateLifePhase({ life_phase: selectedLifePhase.value });
    } catch (err) {
        lifePhaseError.value = err?.response?.data?.message || 'Unable to save life phase right now.';
    } finally {
        lifePhaseBusy.value = false;
    }
};

const handleStartGuidedTour = () => {
    onboardingChoiceError.value = '';
    markOnboardingDecision();
};

const handleSkipGuidedTour = async () => {
    if (onboardingChoiceBusy.value) return;
    onboardingChoiceBusy.value = true;
    onboardingChoiceError.value = '';
    try {
        await skipOnboarding();
        markOnboardingDecision();
        await router.replace('/app');
    } catch (err) {
        onboardingChoiceError.value = err?.response?.data?.message || 'Unable to skip right now.';
    } finally {
        onboardingChoiceBusy.value = false;
    }
};

const syncBillingIfNeeded = async () => {
    if (billingSyncBusy.value) return;
    const sessionId = route.query.session_id;
    const status = route.query.billing;
    if (!sessionId || status !== 'success') {
        return;
    }
    billingSyncBusy.value = true;
    try {
        await completeCheckout(sessionId);
        await ensureBillingStatus(true);
        const cleaned = { ...route.query };
        delete cleaned.session_id;
        delete cleaned.billing;
        router.replace({ query: cleaned });
    } finally {
        billingSyncBusy.value = false;
    }
};


onMounted(() => {
    syncBillingIfNeeded();
    syncLifePhaseDismissed();
    syncOnboardingDecision();
    ensureOnboardingStatus();
});

watch(
    () => route.fullPath,
    () => {
        syncBillingIfNeeded();
    }
);

watch(
    () => authState.user?.id,
    () => {
        selectedLifePhase.value = authState.user?.life_phase || '';
        syncLifePhaseDismissed();
        syncOnboardingDecision();
        ensureOnboardingStatus(true);
    }
);

watch(
    () => route.fullPath,
    () => {
        if (authState.user) {
            ensureOnboardingStatus();
        }
    }
);

watch(
    () => [onboardingState.mode, onboardingState.targetPath, route.path, authState.user?.id],
    () => {
        if (!authState.user) return;
        if (!onboardingState.mode) return;
        if (route.path.startsWith('/admin')) return;
        if (routeAllowedDuringOnboarding(route.path)) return;

        const target = onboardingState.targetPath || '/app';
        if (target !== route.path) {
            router.replace(target);
        }
    },
    { immediate: true }
);
</script>
