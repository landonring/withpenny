<template>
    <section class="screen chat-screen" data-onboarding="chat-screen">
        <header class="chat-topbar">
            <span class="chat-brand">Penny</span>
            <div class="chat-top-actions">
                <button class="chat-refresh" type="button" @click="resetChat">
                    Refresh
                </button>
                <div class="chat-avatar" aria-hidden="true"></div>
            </div>
        </header>

        <main ref="chatCard" class="chat-stream">
            <div class="chat-hero">
                <div class="chat-hero-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path d="M12 5c4.2 0 7.5 2.6 7.5 6.2 0 2.8-1.9 5.2-4.8 6l-2.7 4-2.7-4C6.4 16.4 4.5 14 4.5 11.2 4.5 7.6 7.8 5 12 5z" />
                    </svg>
                </div>
                <h1 class="chat-hero-title">How can I help you find clarity today?</h1>
                <p class="ai-disclaimer">Penny AI can make mistakes. Check important info.</p>
            </div>

            <div class="chat-date">{{ dateLabel }}</div>

            <div class="chat-messages">
                <div
                    v-for="(message, index) in messages"
                    :key="index"
                    :class="['chat-row', message.role]"
                    :data-onboarding="message.role === 'assistant' && index === lastAssistantIndex && index > 0 ? 'chat-response' : null"
                >
                    <div v-if="message.role === 'assistant'" class="chat-bot">
                        <span class="chat-bot-icon"></span>
                    </div>
                    <div :class="['chat-bubble', message.role]">
                        <p>{{ message.text }}</p>
                    </div>
                </div>
                <div v-if="loading" class="chat-row assistant chat-typing">
                    <div class="chat-bot">
                        <span class="chat-bot-icon"></span>
                    </div>
                    <div class="chat-bubble assistant">
                        <span class="typing-dot"></span>
                        <span class="typing-dot"></span>
                        <span class="typing-dot"></span>
                    </div>
                </div>
            </div>
        </main>

        <div class="chat-input-wrap" :class="{ 'onboarding-chat-mode': onboardingState.mode && onboardingState.step === 4 }">
            <div class="chat-fade" :class="{ 'onboarding-hidden': onboardingState.mode && onboardingState.step === 4 }"></div>
            <p v-if="chatUsageText" class="muted chat-usage">{{ chatUsageText }}</p>
            <p v-if="chatLocked" class="form-error chat-usage">You've reached your monthly limit.</p>
            <button v-if="chatLocked" class="ghost-button chat-usage" type="button" @click="openUpgrade">
                Upgrade
            </button>
            <div v-if="showStarterQuestions" class="chat-starters">
                <button
                    v-for="question in starterQuestions"
                    :key="question"
                    class="chat-starter-pill"
                    type="button"
                    :disabled="loading || chatLocked"
                    @click="handleStarterQuestion(question)"
                >
                    {{ question }}
                </button>
            </div>
            <form class="chat-input" data-onboarding="chat" @submit.prevent="handleSend">
                <input
                    v-model="draft"
                    type="text"
                    placeholder="Message Penny..."
                    :disabled="loading || chatLocked"
                />
                <button class="chat-send" type="submit" :disabled="loading || !draft.trim() || chatLocked">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M4 12h11" />
                        <path d="m11 5 7 7-7 7" />
                    </svg>
                </button>
            </form>
        </div>
    </section>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { sendChatMessage } from '../stores/ai';
import { ensureUsageStatus, usageState } from '../stores/usage';
import { showUpgrade } from '../stores/upgrade';
import { onboardingState } from '../stores/onboarding';

const STORAGE_KEY = 'penny.chat.messages';
const defaultMessages = [
    { role: 'assistant', text: "Hi, I'm Penny. We can talk about your money whenever you're ready." },
];

const messages = ref([]);
const chatCard = ref(null);
const starterQuestions = [
    'What is one thing I should focus on this week?',
    'Where did most of my spending go?',
    'How can I reduce wants without feeling restricted?',
];

const draft = ref('');
const loading = ref(false);
const chatUsage = computed(() => usageState.data?.chat?.messages || null);
const isPremium = computed(() => usageState.plan === 'premium');
const chatLocked = computed(() => !!chatUsage.value?.exhausted);
const chatUsageText = computed(() => {
    if (isPremium.value) return '';
    if (!chatUsage.value || chatUsage.value.limit === null) return '';
    return `${chatUsage.value.remaining} of ${chatUsage.value.limit} chat messages left this month`;
});
const showStarterQuestions = computed(() => onboardingState.mode && onboardingState.step === 4 && !chatResponded.value);
const openUpgrade = () => {
    showUpgrade(usageState.plan === 'starter' ? 'pro' : 'premium', 'AI chat');
};

const chatErrorMessage = (err) => {
    const message = err?.response?.data?.message;
    return message || 'Penny is resting right now. You can try again in a little while.';
};

const lastAssistantIndex = computed(() => {
    for (let index = messages.value.length - 1; index >= 0; index -= 1) {
        if (messages.value[index]?.role === 'assistant') {
            return index;
        }
    }

    return -1;
});

const dateLabel = computed(() => {
    const now = new Date();
    const time = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    return `Today, ${time}`;
});

const handleSend = async () => {
    const text = draft.value.trim();
    if (!text || loading.value || chatLocked.value) return;

    messages.value = [...messages.value, { role: 'user', text }];
    draft.value = '';
    loading.value = true;
    persistMessages();
    scrollToBottom();

    try {
        const response = await sendChatMessage(text);
        messages.value = [...messages.value, { role: 'assistant', text: response }];
        await ensureUsageStatus(true);
        persistMessages();
        scrollToBottom();
    } catch (err) {
        messages.value = [
            ...messages.value,
            { role: 'assistant', text: chatErrorMessage(err) },
        ];
        await ensureUsageStatus(true);
        persistMessages();
        scrollToBottom();
    } finally {
        loading.value = false;
    }
};

const chatResponded = computed(() => {
    return messages.value.some((message, index) => message.role === 'assistant' && index > 0);
});

const handleStarterQuestion = async (question) => {
    if (loading.value || chatLocked.value) return;
    draft.value = question;
    await handleSend();
};

const persistMessages = () => {
    if (onboardingState.mode) {
        return;
    }

    if (usageState.plan === 'starter') {
        try {
            localStorage.removeItem(STORAGE_KEY);
        } catch {
            // ignore storage errors
        }
        return;
    }

    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(messages.value));
    } catch {
        // Storage may be unavailable; continue without persisting.
    }
};

const scrollToBottom = () => {
    nextTick(() => {
        if (!chatCard.value) return;
        chatCard.value.scrollTop = chatCard.value.scrollHeight;
    });
};

const loadMessages = () => {
    if (onboardingState.mode) {
        messages.value = [...defaultMessages];
        scrollToBottom();
        return;
    }

    let raw = null;
    try {
        raw = localStorage.getItem(STORAGE_KEY);
    } catch {
        messages.value = [...defaultMessages];
        scrollToBottom();
        return;
    }
    if (!raw) {
        messages.value = [...defaultMessages];
        scrollToBottom();
        return;
    }
    try {
        const parsed = JSON.parse(raw);
        messages.value = Array.isArray(parsed) && parsed.length ? parsed : [...defaultMessages];
    } catch {
        messages.value = [...defaultMessages];
    }
    scrollToBottom();
};

const resetChat = () => {
    messages.value = [...defaultMessages];
    persistMessages();
    scrollToBottom();
};

loadMessages();
onMounted(() => {
    ensureUsageStatus(true).then(() => {
        if (onboardingState.mode || usageState.plan === 'starter') {
            resetChat();
        } else {
            loadMessages();
        }
    });
});

watch(
    () => messages.value.length,
    () => {
        scrollToBottom();
    }
);
</script>
