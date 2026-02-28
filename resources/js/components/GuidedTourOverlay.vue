<template>
    <teleport to="body">
        <div
            v-if="showBackdrop"
            class="onboarding-overlay-backdrop"
            aria-hidden="true"
        ></div>
        <transition name="onboarding-fade" mode="out-in">
            <div
                v-if="overlayActive"
                :key="currentStep.key"
                ref="cardRef"
                class="onboarding-guide-card"
                :class="`onboarding-guide-card--${currentStep.key}`"
                role="dialog"
                aria-modal="true"
                :style="cardStyle"
            >
                <div class="onboarding-guide-meta">
                    <p class="onboarding-guide-step">Guided tour</p>
                    <p class="onboarding-guide-progress">Step {{ currentStep.progressNumber }} of {{ currentStep.totalSteps }}</p>
                </div>
                <div class="onboarding-progress-track" aria-hidden="true">
                    <span class="onboarding-progress-fill" :style="{ width: `${progressPercent}%` }"></span>
                </div>
                <div class="onboarding-progress-dots" aria-hidden="true">
                    <span
                        v-for="index in currentStep.totalSteps"
                        :key="`${currentStep.key}-dot-${index}`"
                        :class="['onboarding-progress-dot', { active: index <= currentStep.progressNumber }]"
                    ></span>
                </div>
                <p class="onboarding-guide-title">{{ currentStep.title }}</p>
                <p class="onboarding-guide-copy">{{ currentStep.body }}</p>
                <p v-if="currentStep.helper" class="onboarding-guide-helper">{{ currentStep.helper }}</p>
                <p v-if="hint" class="onboarding-guide-hint">{{ hint }}</p>

                <div v-if="currentStep.showSavingsSlider" class="onboarding-guide-slider">
                    <label for="onboarding-savings-slider">Sample allocation</label>
                    <input
                        id="onboarding-savings-slider"
                        type="range"
                        min="0"
                        max="100"
                        step="5"
                        :value="savingsSliderValue"
                        @input="handleSavingsSlider"
                    />
                    <span>{{ savingsSliderValue }}%</span>
                </div>

                <div class="onboarding-guide-actions">
                    <button class="ghost-button" type="button" :disabled="!canGoBack || actionBusy" @click="handleBack">
                        Back
                    </button>
                    <button class="primary-button" type="button" :disabled="actionBusy || currentStep.nextDisabled" @click="handleContinue">
                        {{ actionBusy ? 'Working…' : currentStep.actionLabel }}
                    </button>
                </div>
                <div class="onboarding-link-row">
                    <button class="onboarding-skip-link" type="button" :disabled="actionBusy" @click="handlePause">
                        Pause tour
                    </button>
                    <button class="onboarding-skip-link" type="button" :disabled="actionBusy" @click="handleSkip">
                        Skip tour
                    </button>
                </div>
            </div>
        </transition>

        <button v-if="showResumeButton" class="onboarding-resume-pill" type="button" @click="handleResume">
            Resume tour
        </button>

        <transition name="onboarding-fade">
            <div v-if="showCompletionToast" class="onboarding-complete-toast" role="status" aria-live="polite">
                <span class="onboarding-checkmark" aria-hidden="true">✓</span>
                <span>You’re all set. Explore with confidence.</span>
            </div>
        </transition>
    </teleport>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { advanceOnboarding, ensureOnboardingStatus, finishOnboarding, onboardingState, skipOnboarding } from '../stores/onboarding';
import { authState } from '../stores/auth';

const route = useRoute();
const router = useRouter();
const cardRef = ref(null);

const activeTarget = ref(null);
const cardStyle = ref({});
const hint = ref('');
const actionBusy = ref(false);
const homeStepIndex = ref(0);
const scanUploaded = ref(false);
const reviewPreviewIndex = ref(0);
const insightGenerated = ref(false);
const chatResponded = ref(false);
const savingsAdjusted = ref(false);
const savingsSliderValue = ref(25);
const completionReady = ref(false);
const previousBackendStep = ref(null);
const paused = ref(false);
const showCompletionToast = ref(false);

let domObserver = null;
let highlightRetryTimer = null;
let completionToastTimer = null;

const homeTourSteps = [
    {
        key: 'home-month-selector',
        target: 'home-month-selector',
        placement: 'left',
        title: 'Navigate your month.',
        body: 'Use the arrows to move between months. Everything updates automatically.',
        allowInteraction: false,
    },
    {
        key: 'home-summary',
        target: 'home-summary',
        placement: 'left',
        title: 'Your monthly snapshot.',
        body: 'A calm overview of your balance, income, and spending. Think of this as your financial dashboard.',
        allowInteraction: false,
        pulse: true,
    },
    {
        key: 'home-donut',
        target: 'home-donut',
        placement: 'right',
        title: 'Where your money goes.',
        body: 'This chart shows how your spending is divided. It helps you see alignment at a glance.',
        allowInteraction: true,
        scaleIn: true,
    },
    {
        key: 'home-breakdown',
        target: 'home-breakdown',
        placement: 'left',
        title: 'Detailed breakdown.',
        body: 'See exactly how much each category holds. Small adjustments here create clarity.',
        allowInteraction: true,
    },
    {
        key: 'home-bottom-nav',
        target: 'home-bottom-nav',
        placement: 'center-above-nav',
        title: 'Explore Penny.',
        body: 'Scan statements, generate insights, chat, or plan savings. Everything flows from here.',
        allowInteraction: true,
    },
];

const pauseStorageKey = computed(() => {
    const id = authState.user?.id;
    if (!id) return null;
    return `penny.onboarding.pause.${id}`;
});

const overlayActive = computed(() => onboardingState.mode && !!currentStep.value && !paused.value);
const showBackdrop = computed(() =>
    overlayActive.value && !(onboardingState.step === 4 && route.path === '/chat')
);
const showResumeButton = computed(() => onboardingState.mode && paused.value && !!currentStep.value);

const isReviewRoute = computed(() => /^\/statements\/\d+\/review$/.test(route.path));

const currentStep = computed(() => {
    if (!onboardingState.mode) return null;

    if (onboardingState.step === 0) {
        const step = homeTourSteps[Math.min(homeStepIndex.value, homeTourSteps.length - 1)];
        return {
            ...step,
            key: `home-${homeStepIndex.value}-${step.key}`,
            actionLabel: 'Next',
            nextDisabled: false,
            canBack: homeStepIndex.value > 0,
            progressNumber: homeStepIndex.value + 1,
            totalSteps: 12,
        };
    }

    if (onboardingState.step === 1) {
        return {
            key: scanUploaded.value ? 'scan-ready' : 'scan-upload',
            target: 'upload',
            placement: 'right',
            title: 'Upload a statement.',
            body: 'Select a PDF bank statement, or continue with a sample statement to see how review works.',
            helper: 'Upload is optional in guided mode.',
            actionLabel: 'Continue to review',
            nextDisabled: false,
            canBack: false,
            progressNumber: 6,
            totalSteps: 12,
            allowInteraction: false,
        };
    }

    if (onboardingState.step === 2) {
        if (!isReviewRoute.value) {
            return {
                key: 'review-return',
                target: 'upload',
                placement: 'right',
                title: 'Return to review.',
                body: 'Open your staged statement to continue reviewing before you confirm.',
                actionLabel: 'Open review',
                nextDisabled: false,
                canBack: false,
                progressNumber: 7,
                totalSteps: 12,
                allowInteraction: true,
            };
        }

        if (reviewPreviewIndex.value >= 3) {
            return {
                key: 'review-confirm',
                target: 'review-confirm-button',
                placement: 'center-above-nav',
                title: 'Confirm your import.',
                body: 'Nothing is added until you confirm. You are always in control.',
                actionLabel: 'Confirm & Continue',
                nextDisabled: false,
                canBack: true,
                progressNumber: 9,
                totalSteps: 12,
                allowInteraction: true,
            };
        }

        if (reviewPreviewIndex.value === 2) {
            return {
                key: 'review-entry-two',
                target: 'review-entry-2',
                placement: 'right',
                title: 'Review the second entry.',
                body: 'Now look at the spending transaction. You can correct any detail before confirming.',
                helper: 'Continue when this entry looks right.',
                actionLabel: 'Next',
                nextDisabled: false,
                canBack: true,
                progressNumber: 8,
                totalSteps: 12,
                allowInteraction: true,
            };
        }

        if (reviewPreviewIndex.value === 1) {
            return {
                key: 'review-entry-one',
                target: 'review-entry-1',
                placement: 'right',
                title: 'Review the first entry.',
                body: 'Start with the income entry. Check the date, description, and amount.',
                helper: 'Nothing is saved yet. Continue when you are ready.',
                actionLabel: 'Next',
                nextDisabled: false,
                canBack: true,
                progressNumber: 8,
                totalSteps: 12,
                allowInteraction: true,
            };
        }

        return {
            key: 'review-summary',
            target: 'review-summary',
            placement: 'right',
            title: 'Review your statement summary.',
            body: 'Use this snapshot to quickly verify included entries, totals, and extraction confidence.',
            actionLabel: 'Next',
            nextDisabled: false,
            canBack: true,
            progressNumber: 7,
            totalSteps: 12,
            allowInteraction: true,
        };
    }

    if (onboardingState.step === 3) {
        if (insightGenerated.value) {
            return {
                key: 'insights-result',
                target: 'insight-result',
                placement: 'right',
                title: 'Review your insight.',
                body: 'Penny looks for trends in spending and income. These small signals build long-term clarity.',
                actionLabel: 'Continue',
                nextDisabled: false,
                canBack: false,
                progressNumber: 10,
                totalSteps: 12,
                allowInteraction: true,
            };
        }

        return {
            key: 'insights-generate',
            target: 'insight-yearly-button',
            placement: 'right',
            title: 'Generate an insight.',
            body: 'Insights analyze your data and summarize patterns to help you make small, confident adjustments.',
            helper: 'Select Generate yearly overview to continue.',
            actionLabel: 'Next',
            nextDisabled: true,
            canBack: false,
            progressNumber: 10,
            totalSteps: 12,
            allowInteraction: true,
        };
    }

    if (onboardingState.step === 4 && route.path === '/savings') {
        if (completionReady.value) {
            return {
                key: 'savings-complete',
                target: 'savings-primary',
                placement: 'right',
                title: 'Congrats.',
                body: 'You completed the guided tour. Penny is ready whenever you are.',
                actionLabel: 'Done',
                nextDisabled: false,
                canBack: false,
                progressNumber: 12,
                totalSteps: 12,
                allowInteraction: false,
            };
        }

        return {
            key: 'savings-step',
            target: 'savings-primary',
            placement: 'right',
            title: savingsAdjusted.value ? 'You are building momentum.' : 'Plan your future.',
            body: savingsAdjusted.value
                ? 'Small consistent adjustments create real progress over time.'
                : 'Savings goals help you intentionally move money toward what matters most.',
            actionLabel: 'Finish',
            nextDisabled: !savingsAdjusted.value,
            canBack: true,
            progressNumber: 12,
            totalSteps: 12,
            allowInteraction: false,
            showSavingsSlider: true,
        };
    }

    if (onboardingState.step === 4) {
        if (chatResponded.value) {
            return {
                key: 'chat-complete',
                target: 'chat-response',
                placement: 'left',
                title: 'Conversations build clarity.',
                body: 'Use chat anytime you want help understanding your numbers.',
                actionLabel: 'Continue',
                nextDisabled: false,
                canBack: false,
                progressNumber: 11,
                totalSteps: 12,
                allowInteraction: true,
            };
        }

        return {
            key: 'chat-message',
            target: 'chat',
            placement: 'left',
            title: 'Ask Penny a question.',
            body: 'You can ask about spending, categories, or what to focus on next.',
            helper: 'Type a message and press Send, or pick a starter question.',
            actionLabel: 'Next',
            nextDisabled: true,
            canBack: false,
            progressNumber: 11,
            totalSteps: 12,
            allowInteraction: true,
        };
    }

    return null;
});

const canGoBack = computed(() => !!currentStep.value?.canBack);

const progressPercent = computed(() => {
    if (!currentStep.value) return 0;
    return (currentStep.value.progressNumber / currentStep.value.totalSteps) * 100;
});

const routeToStepTarget = async () => {
    const target = onboardingState.targetPath;
    if (!target || target === route.path) return false;
    await router.push(target);
    return true;
};

const setScrollLock = (locked) => {
    if (locked) {
        document.body.classList.add('onboarding-scroll-lock');
        return;
    }
    document.body.classList.remove('onboarding-scroll-lock');
};

const setNavInteraction = (enabled) => {
    if (enabled) {
        document.body.classList.add('onboarding-allow-nav');
        return;
    }
    document.body.classList.remove('onboarding-allow-nav');
};

const persistPause = () => {
    if (typeof window === 'undefined' || !pauseStorageKey.value) return;
    if (!paused.value) {
        localStorage.removeItem(pauseStorageKey.value);
        return;
    }
    const payload = {
        paused: paused.value,
        step: onboardingState.step,
        homeStep: homeStepIndex.value,
        path: route.path,
    };
    localStorage.setItem(pauseStorageKey.value, JSON.stringify(payload));
};

const clearPause = () => {
    paused.value = false;
    if (typeof window !== 'undefined' && pauseStorageKey.value) {
        localStorage.removeItem(pauseStorageKey.value);
    }
};

const restorePause = () => {
    if (typeof window === 'undefined' || !pauseStorageKey.value) return;
    const raw = localStorage.getItem(pauseStorageKey.value);
    if (!raw) return;
    try {
        const parsed = JSON.parse(raw);
        if (!parsed || Number(parsed.step) !== Number(onboardingState.step)) {
            localStorage.removeItem(pauseStorageKey.value);
            return;
        }
        if (onboardingState.step === 0 && Number.isFinite(Number(parsed.homeStep))) {
            homeStepIndex.value = Math.max(0, Math.min(4, Number(parsed.homeStep)));
        }
        paused.value = !!parsed.paused;
    } catch {
        localStorage.removeItem(pauseStorageKey.value);
    }
};

const removeHighlight = () => {
    if (!activeTarget.value) return;
    activeTarget.value.classList.remove('onboarding-target-active');
    activeTarget.value.classList.remove('onboarding-target-pulse');
    activeTarget.value.classList.remove('onboarding-target-scalein');
    activeTarget.value.classList.remove('onboarding-target-donut-tip');
    activeTarget.value.classList.remove('onboarding-target-breakdown-hover');
    activeTarget.value.classList.remove('onboarding-target-nav-glow');
    activeTarget.value.classList.remove('onboarding-target-solid');
    activeTarget.value.classList.remove('onboarding-target-home-summary');
    activeTarget.value.classList.remove('onboarding-target-locked');
    activeTarget.value = null;
    setNavInteraction(false);
};

const resolveTarget = () => {
    if (!currentStep.value) return null;
    if (currentStep.value.selector) {
        return document.querySelector(currentStep.value.selector);
    }

    if (currentStep.value.target) {
        return document.querySelector(`[data-onboarding="${currentStep.value.target}"]`);
    }

    return null;
};

const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

const positionCard = () => {
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    const isMobile = viewportWidth <= 760;
    const cardWidth = Math.min(380, isMobile ? viewportWidth * 0.9 : viewportWidth - 32);
    const margin = isMobile ? 12 : 32;
    const gap = 24;
    const cardHeight = cardRef.value?.offsetHeight || 252;
    const target = activeTarget.value;
    const placement = currentStep.value?.placement || 'auto';

    if (isMobile) {
        cardStyle.value = {
            width: `${cardWidth}px`,
            left: `${(viewportWidth - cardWidth) / 2}px`,
            top: `${Math.max(92, viewportHeight - cardHeight - 24)}px`,
        };
        return;
    }

    if (!target) {
        cardStyle.value = {
            width: `${cardWidth}px`,
            left: `${margin}px`,
            top: '104px',
        };
        return;
    }

    const rect = target.getBoundingClientRect();
    let left = margin;
    let top = 96;

    if (placement === 'top-left') {
        left = rect.left;
        top = rect.bottom + gap;
    } else if (placement === 'left-rail') {
        left = margin;
        top = rect.top + ((rect.height - cardHeight) / 2);
    } else if (placement === 'right') {
        left = rect.right + gap;
        top = rect.top + ((rect.height - cardHeight) / 2);
        if (left + cardWidth > viewportWidth - margin) {
            left = rect.left - cardWidth - gap;
        }
    } else if (placement === 'lower-right') {
        left = rect.right + gap;
        top = rect.top + (rect.height * 0.55);
        if (left + cardWidth > viewportWidth - margin) {
            left = rect.left - cardWidth - gap;
        }
    } else if (placement === 'left') {
        left = rect.left - cardWidth - gap;
        top = rect.top + ((rect.height - cardHeight) / 2);
        if (left < margin) {
            left = rect.right + gap;
        }
    } else if (placement === 'center-above-nav') {
        left = (viewportWidth - cardWidth) / 2;
        top = rect.top - cardHeight - gap;
    } else {
        left = rect.left;
        top = rect.bottom + gap;
    }

    if ((placement === 'top-left' || placement === 'left-rail') && top + cardHeight > viewportHeight - margin) {
        top = rect.top - cardHeight - gap;
    }
    if (placement === 'center-above-nav' && top < margin) {
        top = rect.bottom + gap;
    }

    left = clamp(left, margin, viewportWidth - cardWidth - margin);
    top = clamp(top, margin, viewportHeight - cardHeight - margin);

    cardStyle.value = {
        width: `${cardWidth}px`,
        left: `${left}px`,
        top: `${top}px`,
    };
};

const updateDerivedFlags = () => {
    scanUploaded.value = !!document.querySelector('.page-thumb');
    insightGenerated.value = !!document.querySelector('[data-onboarding="insight-result"]');
    chatResponded.value = !!document.querySelector('[data-onboarding="chat-response"]');
};

const scrollStepTargetIntoView = (target) => {
    if (!target || onboardingState.step === 0) return;

    const scroller = document.querySelector('.main-content');
    if (scroller && typeof scroller.scrollTo === 'function') {
        const scrollerRect = scroller.getBoundingClientRect();
        const targetRect = target.getBoundingClientRect();
        const safeTop = scrollerRect.top + 84;
        const safeBottom = scrollerRect.bottom - 140;
        const isOutsideView = targetRect.top < safeTop || targetRect.bottom > safeBottom;

        if (isOutsideView) {
            const offsetTopInScroller = targetRect.top - scrollerRect.top;
            const centerOffset = Math.max(72, (scroller.clientHeight - targetRect.height) / 2);
            const nextTop = Math.max(0, scroller.scrollTop + offsetTopInScroller - centerOffset);
            scroller.scrollTo({ top: nextTop, behavior: 'smooth' });
            window.setTimeout(() => positionCard(), 260);
        }

        return;
    }

    target.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
    window.setTimeout(() => positionCard(), 260);
};

const applyHighlight = async () => {
    removeHighlight();
    if (!overlayActive.value || !currentStep.value) {
        return;
    }

    await nextTick();
    updateDerivedFlags();

    if (onboardingState.step === 0 && homeStepIndex.value === 0) {
        const scroller = document.querySelector('.main-content');
        if (scroller && typeof scroller.scrollTo === 'function') {
            scroller.scrollTo({ top: 0, behavior: 'auto' });
        } else {
            window.scrollTo(0, 0);
        }
    }

    const target = resolveTarget();
    if (!target) {
        positionCard();
        if (onboardingState.step === 0 && highlightRetryTimer === null) {
            highlightRetryTimer = window.setTimeout(() => {
                highlightRetryTimer = null;
                applyHighlight();
            }, 120);
        }
        return;
    }

    if (highlightRetryTimer !== null) {
        window.clearTimeout(highlightRetryTimer);
        highlightRetryTimer = null;
    }

    activeTarget.value = target;
    activeTarget.value.classList.add('onboarding-target-active');
    if (!currentStep.value.allowInteraction) {
        activeTarget.value.classList.add('onboarding-target-locked');
    }
    if (currentStep.value.pulse) {
        activeTarget.value.classList.add('onboarding-target-pulse');
        window.setTimeout(() => {
            activeTarget.value?.classList.remove('onboarding-target-pulse');
        }, 650);
    }
    if (currentStep.value.scaleIn) {
        activeTarget.value.classList.add('onboarding-target-scalein');
    }
    if (currentStep.value.target === 'home-donut') {
        activeTarget.value.classList.add('onboarding-target-donut-tip');
    }
    if (currentStep.value.target === 'home-breakdown') {
        activeTarget.value.classList.add('onboarding-target-breakdown-hover');
    }
    if (currentStep.value.target === 'home-bottom-nav') {
        activeTarget.value.classList.add('onboarding-target-nav-glow');
        setNavInteraction(true);
    }
    if (
        currentStep.value.target === 'home-summary'
        || currentStep.value.target === 'home-breakdown'
        || currentStep.value.target === 'review-summary'
        || currentStep.value.target === 'review-two-rows'
        || currentStep.value.target === 'insight-result'
    ) {
        activeTarget.value.classList.add('onboarding-target-solid');
    }
    if (currentStep.value.target === 'home-summary') {
        activeTarget.value.classList.add('onboarding-target-home-summary');
    }
    positionCard();
    scrollStepTargetIntoView(target);
};

const handleSavingsSlider = (event) => {
    savingsSliderValue.value = Number(event.target.value);
    savingsAdjusted.value = true;
};

const handleContinue = async () => {
    if (actionBusy.value || !currentStep.value) return;
    hint.value = '';
    actionBusy.value = true;

    try {
        if (onboardingState.step === 0) {
            if (homeStepIndex.value < 4) {
                homeStepIndex.value += 1;
                clearPause();
                return;
            }

            clearPause();
            await advanceOnboarding();
            if (onboardingState.targetPath) {
                await router.push(onboardingState.targetPath);
            }
            return;
        }

        if (onboardingState.step === 1) {
            if (route.path !== '/statements/scan') {
                const routed = await routeToStepTarget();
                if (routed) return;
            }

            const reviewButton = document.querySelector('[data-onboarding-action="upload-review"]');
            if (reviewButton && !reviewButton.disabled) {
                reviewButton.click();
                for (let attempt = 0; attempt < 12; attempt += 1) {
                    if (isReviewRoute.value) {
                        return;
                    }
                    await new Promise((resolve) => window.setTimeout(resolve, 180));
                    await ensureOnboardingStatus(true);
                    if (onboardingState.step >= 2 && onboardingState.targetPath) {
                        await router.push(onboardingState.targetPath);
                        return;
                    }
                }
                hint.value = 'Review import is still processing. Try again in a moment.';
                return;
            }

            hint.value = 'Select Review import to continue.';
            return;
        }

        if (onboardingState.step === 2) {
            if (!isReviewRoute.value) {
                const routed = await routeToStepTarget();
                if (routed) return;
            }

            if (reviewPreviewIndex.value < 3) {
                reviewPreviewIndex.value += 1;
                return;
            }

            const confirmButton = document.querySelector('[data-onboarding-action="review-confirm"]');
            if (confirmButton && !confirmButton.disabled) {
                confirmButton.click();
                return;
            }

            hint.value = 'Confirm import to continue.';
            return;
        }

        if (onboardingState.step === 3) {
            if (route.path !== '/insights') {
                const routed = await routeToStepTarget();
                if (routed) return;
            }

            if (!insightGenerated.value) {
                hint.value = 'Generate yearly overview first.';
                return;
            }

            await advanceOnboarding();
            if (onboardingState.targetPath) {
                await router.push(onboardingState.targetPath);
            }
            return;
        }

        if (onboardingState.step === 4) {
            if (route.path === '/savings') {
                if (completionReady.value) {
                    showCompletionToast.value = true;
                    if (completionToastTimer !== null) {
                        window.clearTimeout(completionToastTimer);
                    }
                    completionToastTimer = window.setTimeout(() => {
                        showCompletionToast.value = false;
                        completionToastTimer = null;
                    }, 2200);

                    await new Promise((resolve) => window.setTimeout(resolve, 2000));
                    await finishOnboarding();
                    await router.push('/app');
                    return;
                }

                if (!savingsAdjusted.value) {
                    hint.value = 'Move the sample allocation slider to continue.';
                    return;
                }

                completionReady.value = true;
                return;
            }

            if (!chatResponded.value) {
                hint.value = 'Send one message in chat to continue.';
                return;
            }

            await router.push('/savings');
            return;
        }
    } finally {
        actionBusy.value = false;
    }
};

const handleBack = async () => {
    if (actionBusy.value || !canGoBack.value || !currentStep.value) return;
    hint.value = '';

    if (onboardingState.step === 0 && homeStepIndex.value > 0) {
        homeStepIndex.value -= 1;
        clearPause();
        return;
    }

    if (onboardingState.step === 2 && isReviewRoute.value) {
        if (reviewPreviewIndex.value > 0) {
            reviewPreviewIndex.value -= 1;
            return;
        }
        await router.push('/statements/scan');
        return;
    }

    if (onboardingState.step === 4 && route.path === '/savings') {
        await router.push('/chat');
    }
};

const handleSkip = async () => {
    if (actionBusy.value) return;
    actionBusy.value = true;
    try {
        clearPause();
        await skipOnboarding();
        await router.push('/app');
    } finally {
        actionBusy.value = false;
    }
};

const handlePause = () => {
    if (actionBusy.value) return;
    paused.value = true;
    persistPause();
    setScrollLock(false);
};

const handleResume = async () => {
    if (actionBusy.value) return;
    paused.value = false;
    clearPause();
    setScrollLock(true);
    await nextTick();
    applyHighlight();
};

const onDocumentFocus = (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
};

const onDocumentClick = (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    if (onboardingState.step === 3 && target.closest('[data-onboarding-action="insight-yearly-generate"]')) {
        window.setTimeout(updateDerivedFlags, 1200);
        window.setTimeout(updateDerivedFlags, 2600);
    }
};

const onDocumentSubmit = (event) => {
    const form = event.target;
    if (!(form instanceof Element)) return;

    if (onboardingState.step === 4 && route.path === '/chat' && form.matches('form[data-onboarding="chat"]')) {
        window.setTimeout(updateDerivedFlags, 900);
        window.setTimeout(updateDerivedFlags, 2500);
    }
};

const onScrollOrResize = () => positionCard();

watch(
    () => [onboardingState.mode, onboardingState.step, route.fullPath],
    () => {
        hint.value = '';

        if (previousBackendStep.value !== onboardingState.step && onboardingState.step === 0) {
            homeStepIndex.value = 0;
        }
        previousBackendStep.value = onboardingState.step;

        if (!onboardingState.mode) {
            homeStepIndex.value = 0;
            reviewPreviewIndex.value = 0;
            insightGenerated.value = false;
            chatResponded.value = false;
            savingsAdjusted.value = false;
            savingsSliderValue.value = 25;
            completionReady.value = false;
            showCompletionToast.value = false;
            clearPause();
        }

        if (onboardingState.step !== 2) {
            reviewPreviewIndex.value = 0;
        }

        if (onboardingState.step !== 4) {
            savingsAdjusted.value = false;
            savingsSliderValue.value = 25;
            completionReady.value = false;
        }

        restorePause();
        setScrollLock(overlayActive.value);
        applyHighlight();
    },
    { immediate: true }
);

watch(
    () => [
        homeStepIndex.value,
        scanUploaded.value,
        reviewPreviewIndex.value,
        insightGenerated.value,
        chatResponded.value,
        savingsAdjusted.value,
    ],
    () => {
        hint.value = '';
        persistPause();
        applyHighlight();
    }
);

watch(
    () => overlayActive.value,
    (value) => {
        setScrollLock(value);
    },
    { immediate: true }
);

onMounted(() => {
    updateDerivedFlags();
    window.addEventListener('resize', onScrollOrResize);
    window.addEventListener('scroll', onScrollOrResize, true);
    document.addEventListener('focusin', onDocumentFocus, true);
    document.addEventListener('click', onDocumentClick, true);
    document.addEventListener('submit', onDocumentSubmit, true);

    domObserver = new MutationObserver(() => {
        updateDerivedFlags();
        if (!activeTarget.value || !document.body.contains(activeTarget.value)) {
            applyHighlight();
            return;
        }
        positionCard();
    });
    domObserver.observe(document.body, {
        childList: true,
        subtree: true,
    });
});

onBeforeUnmount(() => {
    removeHighlight();
    setScrollLock(false);
    setNavInteraction(false);
    if (highlightRetryTimer !== null) {
        window.clearTimeout(highlightRetryTimer);
        highlightRetryTimer = null;
    }
    if (completionToastTimer !== null) {
        window.clearTimeout(completionToastTimer);
        completionToastTimer = null;
    }
    window.removeEventListener('resize', onScrollOrResize);
    window.removeEventListener('scroll', onScrollOrResize, true);
    document.removeEventListener('focusin', onDocumentFocus, true);
    document.removeEventListener('click', onDocumentClick, true);
    document.removeEventListener('submit', onDocumentSubmit, true);
    if (domObserver) {
        domObserver.disconnect();
    }
});
</script>
