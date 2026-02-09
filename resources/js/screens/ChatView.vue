<template>
    <section class="screen chat-screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">Talk with Penny</p>
                <h1 class="screen-title">Talk about your money, gently</h1>
            </div>
            <div class="accent-chip">Chat</div>
        </div>

        <div class="chat-toolbar">
            <span class="muted">Conversation</span>
            <button class="ghost-button" type="button" @click="resetChat">
                Refresh chat
            </button>
        </div>

        <div class="card chat-card">
            <div
                v-for="(message, index) in messages"
                :key="index"
                :class="['chat-bubble', message.role]"
            >
                <p>{{ message.text }}</p>
            </div>
        </div>

        <form class="chat-input" @submit.prevent="handleSend">
            <input
                v-model="draft"
                type="text"
                placeholder="Share what is on your mind"
                :disabled="loading"
            />
            <button class="primary-button" type="submit" :disabled="loading || !draft.trim()">
                {{ loading ? 'Sending…' : 'Send' }}
            </button>
        </form>
    </section>
</template>

<script setup>
import { ref } from 'vue';
import { sendChatMessage } from '../stores/ai';

const STORAGE_KEY = 'penny.chat.messages';
const defaultMessages = [
    { role: 'assistant', text: "Hi, I'm Penny. We can talk about your money whenever you're ready." },
];

const messages = ref([]);

const draft = ref('');
const loading = ref(false);

const handleSend = async () => {
    const text = draft.value.trim();
    if (!text || loading.value) return;

    messages.value = [...messages.value, { role: 'user', text }];
    draft.value = '';
    loading.value = true;
    persistMessages();

    try {
        const response = await sendChatMessage(text);
        messages.value = [...messages.value, { role: 'assistant', text: response }];
        persistMessages();
    } catch (err) {
        messages.value = [
            ...messages.value,
            { role: 'assistant', text: 'Penny is resting right now. You can try again in a little while.' },
        ];
        persistMessages();
    } finally {
        loading.value = false;
    }
};

const persistMessages = () => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(messages.value));
};

const loadMessages = () => {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) {
        messages.value = [...defaultMessages];
        return;
    }
    try {
        const parsed = JSON.parse(raw);
        messages.value = Array.isArray(parsed) && parsed.length ? parsed : [...defaultMessages];
    } catch {
        messages.value = [...defaultMessages];
    }
};

const resetChat = () => {
    messages.value = [...defaultMessages];
    persistMessages();
};

loadMessages();
</script>
