<template>
    <section class="updates-shell" aria-labelledby="updates-title">
        <header class="updates-hero">
            <p class="updates-eyebrow">Penny transparency</p>
            <h1 id="updates-title" class="updates-title">Feedback and Roadmap</h1>
            <p class="updates-subtitle">
                Share ideas, report issues, and follow what is shipping.
            </p>
        </header>

        <section class="updates-panel updates-panel-actions" aria-label="Submit feedback">
            <div class="updates-action-row">
                <button class="updates-pill-button" type="button" @click="openIdeaModal">
                    Suggest a Feature
                </button>
                <button class="updates-pill-button" type="button" @click="openBugModal">
                    Report a Bug
                </button>
            </div>
        </section>

        <section class="updates-panel updates-panel-filters" aria-label="Filter feedback">
            <label class="updates-control">
                <span>Sort</span>
                <select v-model="sort" aria-label="Sort feedback">
                    <option value="top">Most votes</option>
                    <option value="newest">Newest</option>
                </select>
            </label>

            <label class="updates-control">
                <span>Type</span>
                <select v-model="typeFilter" aria-label="Filter by type">
                    <option value="all">All</option>
                    <option value="idea">Features</option>
                    <option value="bug">Bugs</option>
                    <option value="improvement">Improvements</option>
                </select>
            </label>
        </section>

        <div v-if="loading" class="updates-panel updates-empty">Loading updates…</div>
        <div v-else-if="error" class="updates-panel updates-empty">{{ error }}</div>

        <template v-else>
            <section class="updates-section" aria-label="What people are asking for">
                <p class="updates-section-label">What people are asking for.</p>
                <div v-if="!askingItems.length" class="updates-panel updates-empty">Nothing here yet.</div>
                <div v-else class="updates-list">
                    <article
                        v-for="item in askingItems"
                        :key="item.id"
                        class="updates-item"
                        :class="{ expanded: isExpanded(item.id) }"
                        role="button"
                        tabindex="0"
                        :aria-expanded="String(isExpanded(item.id))"
                        @click="toggleExpanded(item.id)"
                        @keydown.enter.prevent="toggleExpanded(item.id)"
                        @keydown.space.prevent="toggleExpanded(item.id)"
                    >
                        <div class="updates-item-grid">
                            <div class="updates-vote-column">
                                <button
                                    class="updates-vote-button"
                                    :class="{ voted: item.has_voted }"
                                    type="button"
                                    :disabled="item.has_voted || voteBusy[item.id]"
                                    :aria-pressed="item.has_voted ? 'true' : 'false'"
                                    :aria-label="item.has_voted ? 'Vote recorded' : 'Vote for this item'"
                                    @click.stop="vote(item)"
                                >
                                    <span class="updates-vote-dot" aria-hidden="true"></span>
                                </button>
                                <span class="updates-vote-count">{{ item.vote_count }}</span>
                                <span v-if="voteThanks[item.id]" class="updates-vote-thanks">Thanks.</span>
                            </div>

                            <div class="updates-item-content">
                                <div class="updates-item-head">
                                    <h3>{{ item.title }}</h3>
                                    <span class="updates-badge" :class="`status-${item.status}`">
                                        {{ statusLabel(item.status) }}
                                    </span>
                                </div>

                                <p class="updates-description">{{ item.description_preview }}</p>

                                <Transition name="updates-expand">
                                    <div
                                        v-if="isExpanded(item.id) && hasMoreText(item)"
                                        class="updates-description-expanded"
                                    >
                                        <p>{{ item.description }}</p>
                                    </div>
                                </Transition>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section class="updates-section" aria-label="What we're building">
                <p class="updates-section-label">What we’re building.</p>
                <div v-if="!roadmapItems.length" class="updates-panel updates-empty">Nothing here yet.</div>
                <div v-else class="updates-list">
                    <article
                        v-for="item in roadmapItems"
                        :key="item.id"
                        class="updates-item"
                        :class="{ expanded: isExpanded(item.id) }"
                        role="button"
                        tabindex="0"
                        :aria-expanded="String(isExpanded(item.id))"
                        @click="toggleExpanded(item.id)"
                        @keydown.enter.prevent="toggleExpanded(item.id)"
                        @keydown.space.prevent="toggleExpanded(item.id)"
                    >
                        <div class="updates-item-grid">
                            <div class="updates-vote-column">
                                <button
                                    class="updates-vote-button"
                                    :class="{ voted: item.has_voted }"
                                    type="button"
                                    :disabled="item.has_voted || voteBusy[item.id]"
                                    :aria-pressed="item.has_voted ? 'true' : 'false'"
                                    :aria-label="item.has_voted ? 'Vote recorded' : 'Vote for this item'"
                                    @click.stop="vote(item)"
                                >
                                    <span class="updates-vote-dot" aria-hidden="true"></span>
                                </button>
                                <span class="updates-vote-count">{{ item.vote_count }}</span>
                                <span v-if="voteThanks[item.id]" class="updates-vote-thanks">Thanks.</span>
                            </div>

                            <div class="updates-item-content">
                                <div class="updates-item-head">
                                    <h3>{{ item.title }}</h3>
                                    <span class="updates-badge" :class="`status-${item.status}`">
                                        {{ statusLabel(item.status) }}
                                    </span>
                                </div>

                                <p class="updates-description">{{ item.description_preview }}</p>

                                <Transition name="updates-expand">
                                    <div
                                        v-if="isExpanded(item.id) && hasMoreText(item)"
                                        class="updates-description-expanded"
                                    >
                                        <p>{{ item.description }}</p>
                                    </div>
                                </Transition>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section class="updates-section" aria-label="Recently shipped">
                <p class="updates-section-label">Recently shipped.</p>
                <div v-if="!announcements.length && !shippedItems.length" class="updates-panel updates-empty">
                    Nothing here yet.
                </div>
                <div v-else class="updates-list">
                    <article
                        v-for="announcement in announcements"
                        :key="`announcement-${announcement.id}`"
                        class="updates-announcement"
                    >
                        <h3>{{ announcement.title }}</h3>
                        <p>{{ announcement.body }}</p>
                    </article>

                    <article
                        v-for="item in shippedItems"
                        :key="item.id"
                        class="updates-item"
                        :class="{ expanded: isExpanded(item.id) }"
                        role="button"
                        tabindex="0"
                        :aria-expanded="String(isExpanded(item.id))"
                        @click="toggleExpanded(item.id)"
                        @keydown.enter.prevent="toggleExpanded(item.id)"
                        @keydown.space.prevent="toggleExpanded(item.id)"
                    >
                        <div class="updates-item-grid">
                            <div class="updates-vote-column">
                                <button
                                    class="updates-vote-button"
                                    :class="{ voted: item.has_voted }"
                                    type="button"
                                    :disabled="item.has_voted || voteBusy[item.id]"
                                    :aria-pressed="item.has_voted ? 'true' : 'false'"
                                    :aria-label="item.has_voted ? 'Vote recorded' : 'Vote for this item'"
                                    @click.stop="vote(item)"
                                >
                                    <span class="updates-vote-dot" aria-hidden="true"></span>
                                </button>
                                <span class="updates-vote-count">{{ item.vote_count }}</span>
                                <span v-if="voteThanks[item.id]" class="updates-vote-thanks">Thanks.</span>
                            </div>

                            <div class="updates-item-content">
                                <div class="updates-item-head">
                                    <h3>{{ item.title }}</h3>
                                    <span class="updates-badge" :class="`status-${item.status}`">
                                        {{ statusLabel(item.status) }}
                                    </span>
                                </div>

                                <p class="updates-description">{{ item.description_preview }}</p>

                                <Transition name="updates-expand">
                                    <div
                                        v-if="isExpanded(item.id) && hasMoreText(item)"
                                        class="updates-description-expanded"
                                    >
                                        <p>{{ item.description }}</p>
                                    </div>
                                </Transition>
                            </div>
                        </div>
                    </article>
                </div>
            </section>
        </template>

        <p class="updates-closing">Thank you for helping make Penny better.</p>
    </section>

    <div
        v-if="showIdeaModal"
        class="updates-modal-backdrop"
        role="dialog"
        aria-modal="true"
        aria-labelledby="idea-modal-title"
        @click.self="closeIdeaModal"
    >
        <div class="updates-modal">
            <template v-if="!ideaSubmitted">
                <h2 id="idea-modal-title">Suggest a Feature</h2>
                <p class="updates-modal-sub">Share what would make Penny more useful for you.</p>

                <label class="updates-field">
                    <span>Title</span>
                    <input v-model="ideaForm.title" type="text" maxlength="160" />
                </label>

                <label class="updates-field">
                    <span>Description</span>
                    <textarea v-model="ideaForm.description" rows="5" maxlength="6000"></textarea>
                </label>

                <label class="updates-field">
                    <span>Email (optional)</span>
                    <input v-model="ideaForm.email" type="email" maxlength="255" />
                </label>

                <p v-if="ideaError" class="form-error">{{ ideaError }}</p>

                <div class="updates-modal-actions">
                    <button class="updates-submit-button" type="button" :disabled="ideaSubmitting" @click="submitIdeaForm">
                        {{ ideaSubmitting ? 'Sending…' : 'Submit' }}
                    </button>
                    <button class="updates-cancel-button" type="button" :disabled="ideaSubmitting" @click="closeIdeaModal">
                        Cancel
                    </button>
                </div>
            </template>

            <template v-else>
                <h2 id="idea-modal-title">Suggestion received</h2>
                <p class="updates-modal-confirmation">
                    Thank you. We’ve added your idea to Penny’s feedback list.
                </p>
                <div class="updates-modal-actions">
                    <button class="updates-submit-button" type="button" @click="closeIdeaModal">Done</button>
                </div>
            </template>
        </div>
    </div>

    <div
        v-if="showBugModal"
        class="updates-modal-backdrop"
        role="dialog"
        aria-modal="true"
        aria-labelledby="bug-modal-title"
        @click.self="closeBugModal"
    >
        <div class="updates-modal">
            <template v-if="!bugSubmitted">
                <h2 id="bug-modal-title">Report a Bug</h2>
                <p class="updates-modal-sub">Tell us what happened and where it happened.</p>

                <label class="updates-field">
                    <span>Title</span>
                    <input v-model="bugForm.title" type="text" maxlength="160" />
                </label>

                <label class="updates-field">
                    <span>Description</span>
                    <textarea v-model="bugForm.description" rows="5" maxlength="6000"></textarea>
                </label>

                <label class="updates-field">
                    <span>Device / browser notes (optional)</span>
                    <input
                        v-model="bugForm.browser_notes"
                        type="text"
                        maxlength="800"
                        placeholder="e.g. iPhone 15, iOS Safari"
                    />
                </label>

                <label class="updates-field">
                    <span>Screenshot (optional)</span>
                    <input type="file" accept="image/*" @change="onBugScreenshotChange" />
                </label>

                <label class="updates-field">
                    <span>Email (optional)</span>
                    <input v-model="bugForm.email" type="email" maxlength="255" />
                </label>

                <p v-if="bugError" class="form-error">{{ bugError }}</p>

                <div class="updates-modal-actions">
                    <button class="updates-submit-button" type="button" :disabled="bugSubmitting" @click="submitBugForm">
                        {{ bugSubmitting ? 'Sending…' : 'Submit' }}
                    </button>
                    <button class="updates-cancel-button" type="button" :disabled="bugSubmitting" @click="closeBugModal">
                        Cancel
                    </button>
                </div>
            </template>

            <template v-else>
                <h2 id="bug-modal-title">Bug report received</h2>
                <p class="updates-modal-confirmation">
                    Thank you. We’ve added your report so we can track and fix it.
                </p>
                <div class="updates-modal-actions">
                    <button class="updates-submit-button" type="button" @click="closeBugModal">Done</button>
                </div>
            </template>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { fetchPublicUpdates, submitBug, submitIdea, voteFeedbackItem } from '../stores/updates';

const loading = ref(true);
const error = ref('');
const sort = ref('top');
const typeFilter = ref('all');
const items = ref([]);
const announcements = ref([]);
const expandedIds = ref([]);

const voteBusy = reactive({});
const voteThanks = reactive({});
const voteTimers = new Map();

const showIdeaModal = ref(false);
const showBugModal = ref(false);
const ideaSubmitted = ref(false);
const bugSubmitted = ref(false);

const ideaForm = reactive({
    title: '',
    description: '',
    email: '',
});
const ideaError = ref('');
const ideaSubmitting = ref(false);

const bugForm = reactive({
    title: '',
    description: '',
    email: '',
    browser_notes: '',
    screenshot: null,
});
const bugError = ref('');
const bugSubmitting = ref(false);

const askingItems = computed(() =>
    items.value.filter((item) => ['submitted', 'reported', 'closed'].includes(item.status))
);

const roadmapItems = computed(() =>
    items.value.filter((item) => ['planned', 'in_progress'].includes(item.status))
);

const shippedItems = computed(() =>
    items.value.filter((item) => item.status === 'shipped')
);

const loadUpdates = async () => {
    loading.value = true;
    error.value = '';

    try {
        const data = await fetchPublicUpdates({ sort: sort.value, type: typeFilter.value });
        items.value = data.items || [];
        announcements.value = data.announcements || [];
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to load updates right now.';
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    loadUpdates();
});

onBeforeUnmount(() => {
    for (const timer of voteTimers.values()) {
        window.clearTimeout(timer);
    }
    voteTimers.clear();
});

watch([sort, typeFilter], () => {
    loadUpdates();
});

const statusLabel = (status) => {
    if (status === 'in_progress') return 'In Progress';
    if (status === 'reported') return 'Submitted';
    return status.charAt(0).toUpperCase() + status.slice(1);
};

const isExpanded = (id) => expandedIds.value.includes(id);

const toggleExpanded = (id) => {
    if (isExpanded(id)) {
        expandedIds.value = expandedIds.value.filter((entry) => entry !== id);
    } else {
        expandedIds.value = [...expandedIds.value, id];
    }
};

const hasMoreText = (item) => (item?.description || '') !== (item?.description_preview || '');

const resortLocal = () => {
    if (sort.value === 'newest') {
        items.value = [...items.value].sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
        return;
    }

    items.value = [...items.value].sort((a, b) => {
        const voteDiff = (b.vote_count || 0) - (a.vote_count || 0);
        if (voteDiff !== 0) return voteDiff;
        return new Date(b.created_at) - new Date(a.created_at);
    });
};

const showThanks = (itemId) => {
    voteThanks[itemId] = true;

    const prior = voteTimers.get(itemId);
    if (prior) {
        window.clearTimeout(prior);
    }

    const timer = window.setTimeout(() => {
        voteThanks[itemId] = false;
        voteTimers.delete(itemId);
    }, 1000);

    voteTimers.set(itemId, timer);
};

const vote = async (item) => {
    if (!item || item.has_voted || voteBusy[item.id]) return;
    voteBusy[item.id] = true;

    try {
        const response = await voteFeedbackItem(item.id);
        item.vote_count = response.vote_count;
        item.has_voted = !!response.has_voted;

        if (response.status === 'voted') {
            showThanks(item.id);
        }

        resortLocal();
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to record vote right now.';
    } finally {
        voteBusy[item.id] = false;
    }
};

const openIdeaModal = () => {
    ideaError.value = '';
    ideaSubmitted.value = false;
    showIdeaModal.value = true;
};

const closeIdeaModal = () => {
    showIdeaModal.value = false;
    ideaSubmitted.value = false;
};

const openBugModal = () => {
    bugError.value = '';
    bugSubmitted.value = false;
    showBugModal.value = true;
};

const closeBugModal = () => {
    showBugModal.value = false;
    bugSubmitted.value = false;
};

const submitIdeaForm = async () => {
    ideaSubmitting.value = true;
    ideaError.value = '';

    try {
        await submitIdea({
            title: ideaForm.title,
            description: ideaForm.description,
            email: ideaForm.email,
        });

        ideaSubmitted.value = true;
        ideaForm.title = '';
        ideaForm.description = '';
        ideaForm.email = '';
        await loadUpdates();
    } catch (err) {
        ideaError.value = err?.response?.data?.message || 'Unable to submit right now.';
    } finally {
        ideaSubmitting.value = false;
    }
};

const onBugScreenshotChange = (event) => {
    bugForm.screenshot = event.target?.files?.[0] || null;
};

const submitBugForm = async () => {
    bugSubmitting.value = true;
    bugError.value = '';

    try {
        await submitBug({
            title: bugForm.title,
            description: bugForm.description,
            email: bugForm.email,
            browser_notes: bugForm.browser_notes,
            screenshot: bugForm.screenshot,
        });

        bugSubmitted.value = true;
        bugForm.title = '';
        bugForm.description = '';
        bugForm.email = '';
        bugForm.browser_notes = '';
        bugForm.screenshot = null;
        await loadUpdates();
    } catch (err) {
        bugError.value = err?.response?.data?.message || 'Unable to submit right now.';
    } finally {
        bugSubmitting.value = false;
    }
};
</script>

<style scoped>
.updates-shell {
    width: min(100%, 980px);
    margin: 0 auto;
    padding: clamp(34px, 5vw, 68px) clamp(18px, 4vw, 32px) calc(80px + env(safe-area-inset-bottom));
    display: grid;
    gap: clamp(22px, 3vw, 30px);
}

.updates-hero {
    display: grid;
    gap: 12px;
    margin-bottom: clamp(14px, 3vw, 24px);
}

.updates-eyebrow {
    margin: 0;
    font-size: 12px;
    color: var(--muted);
    letter-spacing: 0.02em;
}

.updates-title {
    margin: 0;
    font-family: 'Playfair Display', Georgia, serif;
    font-size: clamp(34px, 5vw, 56px);
    line-height: 1.08;
    font-weight: 500;
    color: #2f3733;
}

.updates-subtitle {
    margin: 0;
    max-width: 64ch;
    color: #66655f;
    font-size: 16px;
    line-height: 1.7;
}

.updates-panel {
    background: rgba(255, 255, 255, 0.84);
    border: 1px solid rgba(0, 0, 0, 0.05);
    border-radius: 24px;
    padding: clamp(16px, 2.2vw, 24px);
    box-shadow: 0 12px 32px rgba(33, 28, 20, 0.07);
}

.updates-panel-actions {
    padding-top: clamp(18px, 2.4vw, 26px);
    padding-bottom: clamp(18px, 2.4vw, 26px);
}

.updates-action-row {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.updates-pill-button {
    border: 1px solid rgba(0, 0, 0, 0.07);
    border-radius: 999px;
    padding: 14px 20px;
    background: #f2efe8;
    color: #2f2f2a;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.16s ease, transform 0.16s ease;
}

.updates-pill-button:hover {
    background: #ebe6dc;
}

.updates-pill-button:focus-visible,
.updates-vote-button:focus-visible,
.updates-item:focus-visible,
.updates-control select:focus-visible,
.updates-submit-button:focus-visible,
.updates-cancel-button:focus-visible,
.updates-field input:focus-visible,
.updates-field textarea:focus-visible {
    outline: 2px solid rgba(67, 82, 73, 0.45);
    outline-offset: 2px;
}

.updates-panel-filters {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.updates-control {
    display: grid;
    gap: 6px;
}

.updates-control span {
    font-size: 12px;
    color: #6e6b65;
}

.updates-control select {
    width: 100%;
    border: 1px solid rgba(0, 0, 0, 0.08);
    background: #f7f5f0;
    border-radius: 14px;
    padding: 10px 12px;
    color: #32312d;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
}

.updates-section {
    display: grid;
    gap: 12px;
    margin-top: clamp(8px, 2vw, 16px);
}

.updates-section-label {
    margin: 0;
    font-size: 13px;
    letter-spacing: 0.01em;
    color: #706d67;
}

.updates-list {
    display: grid;
    gap: 14px;
}

.updates-empty {
    text-align: center;
    color: #726f69;
    font-size: 14px;
    padding-top: clamp(18px, 3vw, 26px);
    padding-bottom: clamp(18px, 3vw, 26px);
}

.updates-item {
    background: rgba(255, 255, 255, 0.9);
    border: 1px solid rgba(0, 0, 0, 0.05);
    border-radius: 22px;
    padding: clamp(14px, 2vw, 20px);
    box-shadow: 0 12px 30px rgba(33, 28, 20, 0.06);
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

.updates-item:hover {
    border-color: rgba(0, 0, 0, 0.08);
    box-shadow: 0 16px 34px rgba(33, 28, 20, 0.08);
}

.updates-item-grid {
    display: grid;
    grid-template-columns: 72px minmax(0, 1fr);
    gap: 14px;
    align-items: start;
}

.updates-vote-column {
    display: grid;
    justify-items: center;
    align-content: start;
    gap: 6px;
    padding-top: 1px;
}

.updates-vote-button {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    border: 1px solid rgba(0, 0, 0, 0.14);
    background: #f6f4ee;
    display: grid;
    place-items: center;
    color: #5c5a54;
    cursor: pointer;
    transition: background 0.16s ease, border-color 0.16s ease;
}

.updates-vote-button:hover:not(:disabled) {
    background: #eeebe3;
}

.updates-vote-button.voted {
    background: #dee5de;
    border-color: #9cae9f;
    cursor: default;
}

.updates-vote-button:disabled {
    opacity: 1;
}

.updates-vote-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: currentColor;
    opacity: 0.8;
}

.updates-vote-count {
    font-size: 12px;
    color: #5f5d58;
    line-height: 1;
}

.updates-vote-thanks {
    font-size: 11px;
    color: #5a7260;
    line-height: 1;
}

.updates-item-content {
    min-width: 0;
    display: grid;
    gap: 8px;
}

.updates-item-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.updates-item-head h3 {
    margin: 0;
    font-size: 17px;
    line-height: 1.35;
    font-weight: 600;
    color: #2f312f;
}

.updates-description {
    margin: 0;
    color: #5f5d58;
    font-size: 14px;
    line-height: 1.65;
    white-space: pre-wrap;
}

.updates-description-expanded {
    overflow: hidden;
}

.updates-description-expanded p {
    margin: 0;
    color: #4d4b46;
    font-size: 14px;
    line-height: 1.68;
    white-space: pre-wrap;
}

.updates-badge {
    border-radius: 999px;
    padding: 5px 10px;
    font-size: 11px;
    line-height: 1;
    border: 1px solid rgba(0, 0, 0, 0.09);
    color: #3d3d39;
    white-space: nowrap;
}

.status-submitted,
.status-reported {
    background: #efede8;
    border-color: #ddd9d1;
}

.status-planned {
    background: #e8ede6;
    border-color: #ccd9cc;
}

.status-in_progress {
    background: #dfe8df;
    border-color: #b8cab8;
}

.status-shipped {
    background: #e5edf4;
    border-color: #c5d7e8;
}

.status-closed {
    background: #ebebe8;
    border-color: #d8d8d4;
}

.updates-announcement {
    background: rgba(255, 255, 255, 0.88);
    border: 1px solid rgba(0, 0, 0, 0.05);
    border-radius: 20px;
    padding: clamp(14px, 2vw, 18px);
    box-shadow: 0 10px 26px rgba(33, 28, 20, 0.06);
    display: grid;
    gap: 8px;
}

.updates-announcement h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: #2f312f;
}

.updates-announcement p {
    margin: 0;
    color: #5f5d58;
    font-size: 14px;
    line-height: 1.65;
    white-space: pre-wrap;
}

.updates-closing {
    margin: clamp(20px, 4vw, 34px) 0 0;
    text-align: center;
    color: #7a7771;
    font-size: 14px;
}

.updates-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(27, 27, 27, 0.32);
    display: grid;
    place-items: center;
    padding: 20px;
    z-index: 60;
}

.updates-modal {
    width: min(560px, 100%);
    background: #fbfaf7;
    border-radius: 22px;
    border: 1px solid rgba(0, 0, 0, 0.08);
    box-shadow: 0 24px 44px rgba(0, 0, 0, 0.14);
    padding: clamp(18px, 3vw, 24px);
    display: grid;
    gap: 12px;
}

.updates-modal h2 {
    margin: 0;
    font-size: 24px;
    font-family: 'Playfair Display', Georgia, serif;
    font-weight: 500;
    color: #2f3733;
}

.updates-modal-sub {
    margin: 0;
    color: #66655f;
    font-size: 14px;
}

.updates-field {
    display: grid;
    gap: 6px;
}

.updates-field span {
    font-size: 12px;
    color: #6d6a64;
}

.updates-field input,
.updates-field textarea {
    width: 100%;
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 14px;
    background: #fff;
    padding: 11px 12px;
    font-size: 15px;
    color: #2f312f;
}

.updates-field textarea {
    resize: vertical;
    min-height: 120px;
}

.updates-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 2px;
}

.updates-submit-button,
.updates-cancel-button {
    border-radius: 999px;
    padding: 11px 18px;
    font-size: 14px;
    font-weight: 600;
    border: 1px solid transparent;
    cursor: pointer;
}

.updates-submit-button {
    background: #dcd4c7;
    color: #302f2b;
}

.updates-submit-button:hover:not(:disabled) {
    background: #d0c8ba;
}

.updates-cancel-button {
    background: #ece8e0;
    color: #4e4c46;
    border-color: rgba(0, 0, 0, 0.06);
}

.updates-cancel-button:hover:not(:disabled) {
    background: #e4dfd5;
}

.updates-submit-button:disabled,
.updates-cancel-button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.updates-modal-confirmation {
    margin: 0;
    color: #5f5d58;
    font-size: 15px;
    line-height: 1.6;
}

.updates-expand-enter-active,
.updates-expand-leave-active {
    transition: max-height 0.22s ease, opacity 0.2s ease;
    max-height: 280px;
}

.updates-expand-enter-from,
.updates-expand-leave-to {
    max-height: 0;
    opacity: 0;
}

@media (max-width: 840px) {
    .updates-shell {
        width: min(100%, 760px);
    }
}

@media (max-width: 720px) {
    .updates-shell {
        padding-left: 16px;
        padding-right: 16px;
    }

    .updates-action-row,
    .updates-panel-filters {
        grid-template-columns: 1fr;
    }

    .updates-item-grid {
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .updates-vote-column {
        justify-items: start;
        grid-auto-flow: column;
        align-items: center;
        gap: 8px;
    }

    .updates-vote-count,
    .updates-vote-thanks {
        line-height: 1;
    }

    .updates-item-head {
        flex-direction: column;
        align-items: flex-start;
    }

    .updates-modal-actions {
        flex-direction: column;
    }

    .updates-submit-button,
    .updates-cancel-button {
        width: 100%;
    }
}
</style>
