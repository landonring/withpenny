<template>
    <section class="admin-users" :class="{ 'sidebar-open': sidebarOpen }">
        <aside class="admin-users__sidebar">
            <div class="admin-users__search">
                <span class="admin-users__search-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </span>
                <input v-model="search" type="search" placeholder="Search User" />
            </div>

            <div class="admin-users__section">
                <h3>Filter by Status</h3>
                <label>
                    <input type="radio" value="paying" v-model="statusFilter" />
                    <span class="dot dot--paying"></span>
                    Paying
                </label>
                <label>
                    <input type="radio" value="free" v-model="statusFilter" />
                    <span class="dot dot--free"></span>
                    Free
                </label>
                <label>
                    <input type="radio" value="neither" v-model="statusFilter" />
                    <span class="dot dot--neutral"></span>
                    Neither
                </label>
            </div>

            <div class="admin-users__section">
                <h3>Search User</h3>
                <input v-model="search" type="search" placeholder="Search User" />
            </div>

            <div class="admin-users__section">
                <h3>Date Range</h3>
                <select v-model="dateRange">
                    <option value="all">Date to Range</option>
                    <option value="30">Last 30 Days</option>
                    <option value="90">Last Quarter</option>
                </select>
            </div>

            <div class="admin-users__section">
                <h3>Admin Center</h3>
                <button class="admin-users__link-btn" type="button" @click="goToDashboard">Dashboard</button>
                <button class="admin-users__link-btn" type="button" @click="goToUpdates">Roadmap &amp; Feedback</button>
            </div>

            <div class="admin-users__footer">
                <p>Admin Dashboard v2.4</p>
                <p>© 2023 Penny Health</p>
            </div>
        </aside>

        <div class="admin-users__overlay" v-if="sidebarOpen" @click="sidebarOpen = false"></div>

        <main class="admin-users__main">
            <header class="admin-users__header">
                <button class="admin-users__menu" type="button" @click="sidebarOpen = true">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <div class="admin-users__header-actions">
                    <button class="admin-users__dashboard-btn" type="button" @click="goToUsers">
                        Users
                    </button>
                    <button class="admin-users__dashboard-btn" type="button" @click="goToUpdates">
                        Updates
                    </button>
                    <button class="admin-users__dashboard-btn admin-users__refresh-btn" type="button" :disabled="loading" @click="refreshUsers">
                        {{ loading ? 'Refreshing…' : 'Refresh' }}
                    </button>
                </div>
                <div class="admin-users__breadcrumbs">
                    Penny Health <span>/</span> Admin <span>/</span> Network Visualization
                </div>
                <h1>Penny User Network Visualization</h1>
            </header>

            <div ref="canvasRef" class="admin-users__canvas">
                <div class="admin-users__canvas-bg"></div>

                <svg class="admin-users__lines" :width="canvasSize.width" :height="canvasSize.height">
                    <defs>
                        <linearGradient id="node-line" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stop-color="rgba(227, 216, 198, 0.18)" />
                            <stop offset="100%" stop-color="rgba(227, 216, 198, 0.5)" />
                        </linearGradient>
                    </defs>
                    <line
                        v-for="node in nodes"
                        :key="`line-${node.id}`"
                        :x1="center.x"
                        :y1="center.y"
                        :x2="node.x"
                        :y2="node.y"
                        :class="['admin-users__line', { 'is-active': node.id === hoveredNodeId || node.id === activeUser?.id }]"
                        :style="{ '--delay': `${node.delay}ms` }"
                    />
                </svg>

                <div class="admin-users__center" :style="{ left: `${center.x}px`, top: `${center.y}px` }">
                    <div class="admin-users__center-glow"></div>
                    <div class="admin-users__center-ring"></div>
                    <div class="admin-users__center-core">
                        <img alt="Penny" :src="pennyLogo" />
                    </div>
                    <div class="admin-users__center-label">Penny Health</div>
                </div>

                <div class="admin-users__nodes" ref="nodesLayerRef">
                    <button
                        v-for="node in nodes"
                        :key="node.id"
                        class="admin-users__node"
                        :class="[`admin-users__node--${node.status}`, `admin-users__node--${node.plan}`]"
                        :style="{
                            left: `${node.x}px`,
                            top: `${node.y}px`,
                            width: `${node.size}px`,
                            height: `${node.size}px`,
                            '--delay': `${node.delay}ms`
                        }"
                        type="button"
                        @mouseenter="hoveredNodeId = node.id"
                        @mouseleave="hoveredNodeId = null"
                        @click="selectUser(node)"
                    >
                        <img v-if="node.avatar" :src="node.avatar" alt="" />
                        <span v-else>{{ node.initials }}</span>
                    </button>
                </div>

                <transition name="admin-users-card">
                    <div v-if="activeUser" ref="cardRef" class="admin-users__card" :style="cardStyle">
                        <div class="admin-users__card-header" @pointerdown="startDrag">
                            <div class="admin-users__card-avatar">
                                {{ activeNode?.initials || 'P' }}
                            </div>
                            <div>
                                <h3>{{ activeUser.name || 'Untitled user' }}</h3>
                                <p>{{ activeUser.email }}</p>
                            </div>
                        </div>
                        <div class="admin-users__card-grid">
                            <div>Status</div>
                            <div>
                                <span
                                    class="admin-users__status-dot"
                                    :class="activeNode?.status || 'free'"
                                ></span>
                                {{ activeNode?.status === 'paying' ? 'Paying Member' : 'Free Member' }}
                            </div>
                            <div>Plan</div>
                            <div>{{ planLabel(activeUser.plan) }}</div>
                            <div>Joined</div>
                            <div>{{ formatDate(activeUser.created_at) }}</div>
                            <div>Last Activity</div>
                            <div>{{ formatDate(activeUser.last_login) }}</div>
                            <div>Location</div>
                            <div>{{ activeUser.location || '—' }}</div>
                            <div>Connections</div>
                            <div>{{ activeUser.connections ?? '—' }}</div>
                        </div>
                        <div class="admin-users__card-actions">
                            <button type="button" :disabled="impersonateBusy" @click="impersonateUser">
                                {{ impersonateBusy ? 'Logging in…' : 'Login' }}
                            </button>
                        </div>
                    </div>
                </transition>

                <div v-if="loading" class="admin-users__status">Loading users…</div>
                <div v-else-if="error" class="admin-users__status admin-users__status--error">
                    {{ error }}
                </div>
                <div v-else-if="!nodes.length" class="admin-users__status">
                    No users match this filter.
                </div>
            </div>
        </main>
    </section>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { applyAuthPayload } from '../stores/auth';

const router = useRouter();

const users = ref([]);
const loading = ref(false);
const error = ref('');

const search = ref('');
const statusFilter = ref('neither');
const dateRange = ref('all');
const activeUser = ref(null);
const hoveredNodeId = ref(null);

const sidebarOpen = ref(false);
const impersonateBusy = ref(false);
const pennyLogo = '/icons/penny-192.png';

const canvasRef = ref(null);
const cardRef = ref(null);
const nodesLayerRef = ref(null);
const canvasSize = ref({ width: 0, height: 0 });
const cardPosition = ref({ x: 0, y: 0 });
const dragging = ref(false);
const dragOffset = ref({ x: 0, y: 0 });

const fetchUsers = async () => {
    loading.value = true;
    error.value = '';

    try {
        const { data } = await axios.get('/admin/users/network-data');
        users.value = data?.users || [];
        activeUser.value = users.value[0] || null;
    } catch (err) {
        if (err?.response?.status === 403) {
            router.push('/app');
            return;
        }
        error.value = 'Unable to load users right now.';
    } finally {
        loading.value = false;
    }
};

const matchesSearch = (user, term) => {
    const text = `${user.name || ''} ${user.email || ''}`.toLowerCase();
    return text.includes(term);
};

const inDateRange = (user, range) => {
    if (range === 'all') return true;
    const days = Number(range);
    if (!days) return true;
    const created = user.created_at ? new Date(user.created_at) : null;
    if (!created || Number.isNaN(created.getTime())) return true;
    const cutoff = new Date();
    cutoff.setDate(cutoff.getDate() - days);
    return created >= cutoff;
};

const filteredUsers = computed(() => {
    const term = search.value.trim().toLowerCase();
    return users.value.filter((user) => {
        if (term && !matchesSearch(user, term)) return false;
        if (!inDateRange(user, dateRange.value)) return false;
        if (statusFilter.value === 'paying' && user.status !== 'paying') return false;
        if (statusFilter.value === 'free' && user.status !== 'free') return false;
        return true;
    });
});

const center = computed(() => ({
    x: canvasSize.value.width / 2,
    y: canvasSize.value.height / 2,
}));

const planRank = (plan) => {
    if (plan === 'premium') return 3;
    if (plan === 'pro') return 2;
    return 1;
};

const nodeSizeForPlan = (plan) => {
    if (plan === 'premium') return 56;
    if (plan === 'pro') return 48;
    return 40;
};

const buildRadialLayout = (items, size) => {
    const width = size.width || 1;
    const height = size.height || 1;
    const centerX = width / 2;
    const centerY = height / 2;
    const margin = Math.max(90, Math.min(width, height) * 0.08);
    const maxRadius = Math.max(160, Math.min(width, height) / 2 - margin);

    const sorted = [...items].sort((a, b) => {
        const rankDiff = planRank(b.plan) - planRank(a.plan);
        if (rankDiff !== 0) return rankDiff;
        return (a.id || 0) - (b.id || 0);
    });
    const nodes = [];
    let ringIndex = 0;
    let placed = 0;
    const seed = hashString(sorted.map((user) => user.id).join('|'));
    const rng = mulberry32(seed || 1);
    let angleOffset = rng() * Math.PI * 2;

    const baseRadius = Math.min(width, height) * 0.28;
    const ringGap = Math.max(56, Math.min(width, height) * 0.075);

    while (placed < sorted.length) {
        const radius = Math.min(baseRadius + ringIndex * ringGap, maxRadius);
        const sampleSize = nodeSizeForPlan(sorted[placed]?.plan);
        const circumference = 2 * Math.PI * radius;
        const capacity = Math.max(8, Math.floor(circumference / (sampleSize + 16)));
        const count = Math.min(sorted.length - placed, capacity);

        for (let i = 0; i < count; i += 1) {
            const user = sorted[placed + i];
            const sizePx = nodeSizeForPlan(user.plan);
            const angle = angleOffset + (i / count) * Math.PI * 2;
            const x = centerX + Math.cos(angle) * radius;
            const y = centerY + Math.sin(angle) * radius;
            nodes.push({
                ...user,
                x,
                y,
                size: sizePx,
                initials: initialsFor(user.name),
                delay: (ringIndex * 70) + i * 12,
            });
        }

        placed += count;
        ringIndex += 1;
        angleOffset += Math.PI / (count || 1);
        if (radius >= maxRadius) {
            break;
        }
    }

    if (placed < sorted.length) {
        const remaining = sorted.slice(placed);
        const radius = maxRadius;
        const count = remaining.length;
        remaining.forEach((user, index) => {
            const sizePx = nodeSizeForPlan(user.plan);
            const angle = angleOffset + (index / count) * Math.PI * 2;
            const x = centerX + Math.cos(angle) * radius;
            const y = centerY + Math.sin(angle) * radius;
            nodes.push({
                ...user,
                x,
                y,
                size: sizePx,
                initials: initialsFor(user.name),
                delay: (ringIndex * 70) + index * 12,
            });
        });
    }

    return nodes;
};

const nodes = computed(() => buildRadialLayout(filteredUsers.value, canvasSize.value));

const activeNode = computed(() => nodes.value.find((node) => node.id === activeUser.value?.id));

const selectUser = (node) => {
    activeUser.value = users.value.find((user) => user.id === node.id) || node;
};

const formatDate = (value) => {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleDateString();
};

const planLabel = (plan) => {
    if (plan === 'pro') return 'Pro';
    if (plan === 'premium') return 'Premium';
    return 'Free';
};

const initialsFor = (name) => {
    if (!name) return 'U';
    const parts = name.trim().split(' ');
    const first = parts[0]?.[0] || 'U';
    const last = parts[1]?.[0] || '';
    return `${first}${last}`.toUpperCase();
};

const hashString = (value) => {
    let hash = 2166136261;
    for (let i = 0; i < value.length; i += 1) {
        hash ^= value.charCodeAt(i);
        hash = Math.imul(hash, 16777619);
    }
    return hash >>> 0;
};

const mulberry32 = (seed) => {
    let t = seed;
    return () => {
        t += 0x6D2B79F5;
        let r = Math.imul(t ^ (t >>> 15), t | 1);
        r ^= r + Math.imul(r ^ (r >>> 7), r | 61);
        return ((r ^ (r >>> 14)) >>> 0) / 4294967296;
    };
};

const updateCanvasSize = () => {
    if (!canvasRef.value) return;
    const rect = canvasRef.value.getBoundingClientRect();
    canvasSize.value = { width: rect.width, height: rect.height };
};

const ensureCsrf = async () => {
    if (axios?.defaults?.headers?.common?.['X-CSRF-TOKEN']) return;
    try {
        const { data } = await axios.get('/api/csrf');
        if (data?.csrf_token) {
            axios.defaults.headers.common['X-CSRF-TOKEN'] = data.csrf_token;
        }
    } catch {
        // ignore
    }
};

const impersonateUser = async () => {
    if (!activeUser.value || impersonateBusy.value) return;
    impersonateBusy.value = true;
    try {
        await ensureCsrf();
        const { data } = await axios.post(`/admin/impersonate/${activeUser.value.id}`);
        applyAuthPayload(data);
        router.push('/app');
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to log in as this user.';
    } finally {
        impersonateBusy.value = false;
    }
};

const goToDashboard = () => {
    router.push('/admin/dashboard');
};

const goToUsers = () => {
    router.push('/admin/users');
};

const goToUpdates = () => {
    router.push('/admin/roadmap-feedback');
};

const refreshUsers = () => {
    fetchUsers();
};

const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

const setDefaultCardPosition = () => {
    if (!canvasRef.value || !cardRef.value) return;
    const canvasRect = canvasRef.value.getBoundingClientRect();
    const cardRect = cardRef.value.getBoundingClientRect();
    const x = canvasRect.width - cardRect.width - 40;
    const y = canvasRect.height / 2 - cardRect.height / 2;
    cardPosition.value = {
        x: clamp(x, 16, canvasRect.width - cardRect.width - 16),
        y: clamp(y, 16, canvasRect.height - cardRect.height - 16),
    };
};

const startDrag = (event) => {
    if (!canvasRef.value || !cardRef.value) return;
    dragging.value = true;
    const cardRect = cardRef.value.getBoundingClientRect();
    dragOffset.value = {
        x: event.clientX - cardRect.left,
        y: event.clientY - cardRect.top,
    };

    const onMove = (moveEvent) => {
        if (!dragging.value || !canvasRef.value || !cardRef.value) return;
        const canvasRect = canvasRef.value.getBoundingClientRect();
        const cardBounds = cardRef.value.getBoundingClientRect();
        const x = moveEvent.clientX - canvasRect.left - dragOffset.value.x;
        const y = moveEvent.clientY - canvasRect.top - dragOffset.value.y;
        cardPosition.value = {
            x: clamp(x, 16, canvasRect.width - cardBounds.width - 16),
            y: clamp(y, 16, canvasRect.height - cardBounds.height - 16),
        };
    };

    const onUp = () => {
        dragging.value = false;
        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', onUp);
    };

    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
};

const cardStyle = computed(() => ({
    left: `${cardPosition.value.x}px`,
    top: `${cardPosition.value.y}px`,
}));

const handleOutsideClick = (event) => {
    if (!activeUser.value) return;
    if (cardRef.value?.contains(event.target)) return;
    if (nodesLayerRef.value?.contains(event.target)) return;
    activeUser.value = null;
};

watch(filteredUsers, (list) => {
    if (!list.length) {
        activeUser.value = null;
        return;
    }
    if (!list.find((user) => user.id === activeUser.value?.id)) {
        activeUser.value = list[0];
    }
});

onMounted(async () => {
    await fetchUsers();
    await nextTick();
    updateCanvasSize();
    setDefaultCardPosition();
    window.addEventListener('resize', updateCanvasSize);
    window.addEventListener('pointerdown', handleOutsideClick);
});

onUnmounted(() => {
    window.removeEventListener('resize', updateCanvasSize);
    window.removeEventListener('pointerdown', handleOutsideClick);
});

watch(activeUser, async () => {
    await nextTick();
    setDefaultCardPosition();
});
</script>

<style scoped>
.admin-users {
    display: flex;
    height: 100vh;
    background: #1f2b26;
    color: #f9f6f0;
    font-family: 'Inter', sans-serif;
    position: relative;
    overflow: hidden;
}

.admin-users__sidebar {
    width: 280px;
    background: rgba(24, 35, 31, 0.88);
    border-right: 1px solid rgba(255, 255, 255, 0.06);
    box-shadow: inset 0 0 20px rgba(0, 0, 0, 0.35);
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 20px;
    position: relative;
    z-index: 5;
    backdrop-filter: blur(10px);
}

.admin-users__search {
    position: relative;
}

.admin-users__search input {
    width: 100%;
    background: rgba(44, 62, 54, 0.9);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    padding: 10px 12px 10px 38px;
    color: #f4f1ea;
    font-size: 14px;
}

.admin-users__search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    width: 16px;
    height: 16px;
    transform: translateY(-50%);
    color: rgba(160, 174, 192, 0.9);
}

.admin-users__section h3 {
    font-size: 11px;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: rgba(160, 174, 192, 0.8);
    margin-bottom: 10px;
}

.admin-users__section label {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    color: rgba(255, 255, 255, 0.82);
    margin-bottom: 10px;
}

.admin-users__section input[type='radio'] {
    accent-color: #4caf50;
}

.admin-users__section input[type='search'],
.admin-users__section select {
    width: 100%;
    padding: 9px 12px;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    background: rgba(44, 62, 54, 0.9);
    color: #f4f1ea;
    font-size: 14px;
}

.admin-users__link-btn {
    width: 100%;
    padding: 9px 12px;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    background: rgba(44, 62, 54, 0.9);
    color: #f4f1ea;
    font-size: 13px;
    text-align: left;
    margin-bottom: 8px;
}

.dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
}

.dot--paying {
    background: #7fa58f;
    box-shadow: 0 0 8px rgba(127, 165, 143, 0.6);
}

.dot--free {
    background: #e3d8c6;
}

.dot--neutral {
    background: rgba(255, 255, 255, 0.45);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.2);
}

.admin-users__footer {
    margin-top: auto;
    font-size: 11px;
    color: rgba(160, 174, 192, 0.7);
}

.admin-users__main {
    flex: 1;
    position: relative;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.admin-users__header {
    text-align: center;
    padding: 26px 20px 10px;
    position: relative;
    z-index: 3;
}

.admin-users__breadcrumbs {
    font-size: 11px;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: rgba(156, 196, 175, 0.8);
    margin-bottom: 8px;
}

.admin-users__breadcrumbs span {
    margin: 0 6px;
    color: rgba(255, 255, 255, 0.4);
}

.admin-users__header h1 {
    font-family: 'Playfair Display', serif;
    font-size: clamp(24px, 3vw, 36px);
    margin: 0;
    color: #f6f2ea;
}

.admin-users__menu {
    position: absolute;
    left: 20px;
    top: 28px;
    display: none;
    flex-direction: column;
    gap: 5px;
    background: transparent;
    border: none;
    cursor: pointer;
    padding: 0;
}

.admin-users__menu span {
    width: 22px;
    height: 2px;
    background: rgba(246, 242, 234, 0.8);
    border-radius: 999px;
}

.admin-users__header-actions {
    position: absolute;
    right: 24px;
    top: 24px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.admin-users__dashboard-btn {
    padding: 8px 16px;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, 0.16);
    background: rgba(0, 0, 0, 0.2);
    color: rgba(246, 242, 234, 0.9);
    font-size: 12px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    cursor: pointer;
    backdrop-filter: blur(6px);
}

.admin-users__dashboard-btn:hover {
    border-color: rgba(255, 255, 255, 0.3);
    color: #ffffff;
}

.admin-users__refresh-btn:disabled {
    opacity: 0.7;
    cursor: default;
}

.admin-users__canvas {
    flex: 1;
    position: relative;
    overflow: hidden;
}

.admin-users__canvas-bg {
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at center, #2c3e36 0%, #1f2b26 70%);
    z-index: 0;
}

.admin-users__lines {
    position: absolute;
    inset: 0;
    z-index: 1;
    pointer-events: none;
}

.admin-users__line {
    stroke: rgba(227, 216, 198, 0.22);
    stroke-width: 1;
    opacity: 0;
    animation: line-reveal 1.5s ease forwards;
    animation-delay: var(--delay);
}

.admin-users__line.is-active {
    stroke: rgba(180, 220, 200, 0.8);
    filter: drop-shadow(0 0 6px rgba(141, 196, 176, 0.55));
    opacity: 1;
}

.admin-users__center {
    position: absolute;
    z-index: 2;
    width: 120px;
    height: 120px;
    transform: translate(-50%, -50%);
    display: flex;
    align-items: center;
    justify-content: center;
}

.admin-users__center-glow {
    position: absolute;
    inset: -18px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(127, 165, 143, 0.35), rgba(127, 165, 143, 0));
    animation: pulse 4s ease-in-out infinite;
}

.admin-users__center-ring {
    position: absolute;
    inset: 6px;
    border-radius: 50%;
    border: 1px solid rgba(255, 255, 255, 0.15);
}

.admin-users__center-core {
    position: relative;
    width: 96px;
    height: 96px;
    background: #3b6db0;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 0 30px rgba(59, 109, 176, 0.4);
    border: 4px solid #1f2b26;
    overflow: hidden;
}

.admin-users__center-core img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.admin-users__center-label {
    position: absolute;
    top: calc(100% + 10px);
    width: 200px;
    text-align: center;
    font-family: 'Playfair Display', serif;
    font-size: 16px;
    color: rgba(246, 242, 234, 0.9);
}

.admin-users__nodes {
    position: absolute;
    inset: 0;
    z-index: 2;
}

.admin-users__node {
    position: absolute;
    transform: translate(-50%, -50%);
    border-radius: 50%;
    border: 1px solid rgba(255, 255, 255, 0.2);
    display: grid;
    place-items: center;
    font-weight: 600;
    font-size: 13px;
    color: #1f2b26;
    box-shadow: 0 8px 18px rgba(0, 0, 0, 0.3);
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    opacity: 0;
    animation: node-in 0.8s ease forwards;
    animation-delay: var(--delay);
}

.admin-users__node img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}

.admin-users__node:hover {
    transform: translate(-50%, -50%) scale(1.08);
    box-shadow: 0 12px 22px rgba(0, 0, 0, 0.4);
}

.admin-users__node--paying {
    background: #7fa58f;
}

.admin-users__node--free {
    background: #e3d8c6;
}

.admin-users__node--premium {
    box-shadow: 0 10px 26px rgba(0, 0, 0, 0.4), 0 0 18px rgba(127, 165, 143, 0.5);
}

.admin-users__card {
    position: absolute;
    background: #f9f6f0;
    color: #1f2b26;
    padding: 22px 24px;
    border-radius: 18px;
    width: 320px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35);
    z-index: 4;
}

.admin-users__card-header {
    display: flex;
    gap: 14px;
    align-items: center;
    margin-bottom: 16px;
    cursor: grab;
    user-select: none;
}

.admin-users__card-avatar {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background: #1f2b26;
    color: #f9f6f0;
    display: grid;
    place-items: center;
    font-weight: 600;
}

.admin-users__card-header h3 {
    font-family: 'Playfair Display', serif;
    margin: 0;
    font-size: 20px;
}

.admin-users__card-actions {
    display: flex;
    justify-content: flex-end;
}

.admin-users__card-actions button {
    padding: 10px 18px;
    border-radius: 999px;
    border: 1px solid rgba(31, 43, 38, 0.2);
    background: #f3efe6;
    font-size: 13px;
    cursor: pointer;
}

.admin-users__card-actions button:disabled {
    opacity: 0.6;
    cursor: default;
}

.admin-users__card-header p {
    margin: 4px 0 0;
    font-size: 13px;
    color: rgba(31, 43, 38, 0.6);
}

.admin-users__card-grid {
    display: grid;
    grid-template-columns: 110px 1fr;
    row-gap: 10px;
    font-size: 13px;
    color: rgba(31, 43, 38, 0.7);
    margin-bottom: 18px;
}

.admin-users__card-grid div:nth-child(2n) {
    color: #1f2b26;
    font-weight: 600;
}

.admin-users__status-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 8px;
}

.admin-users__status-dot.paying {
    background: #7fa58f;
}

.admin-users__status-dot.free {
    background: #e3d8c6;
}


.admin-users__status {
    position: absolute;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(24, 35, 31, 0.6);
    padding: 8px 16px;
    border-radius: 999px;
    font-size: 13px;
    color: rgba(255, 255, 255, 0.8);
    z-index: 5;
}

.admin-users__status--error {
    color: #ffd7d7;
}

.admin-users-card-enter-active,
.admin-users-card-leave-active {
    transition: opacity 0.3s ease, transform 0.3s ease;
}

.admin-users-card-enter-from,
.admin-users-card-leave-to {
    opacity: 0;
    transform: translateY(-50%) translateX(20px);
}

.admin-users__overlay {
    display: none;
}

@keyframes pulse {
    0%,
    100% {
        opacity: 0.6;
        transform: scale(0.96);
    }
    50% {
        opacity: 1;
        transform: scale(1);
    }
}

@keyframes node-in {
    from {
        opacity: 0;
        transform: translate(-50%, -50%) scale(0.8);
    }
    to {
        opacity: 1;
        transform: translate(-50%, -50%) scale(1);
    }
}

@keyframes line-reveal {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@media (max-width: 1024px) {
    .admin-users__menu {
        display: flex;
    }

    .admin-users__sidebar {
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        transform: translateX(-100%);
        transition: transform 0.3s ease;
    }

    .admin-users.sidebar-open .admin-users__sidebar {
        transform: translateX(0);
    }

    .admin-users__overlay {
        display: block;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.4);
        z-index: 4;
    }

    .admin-users__card {
        width: 300px;
    }

    .admin-users__header-actions {
        right: 16px;
        top: 18px;
        gap: 8px;
    }

    .admin-users__dashboard-btn {
        padding: 7px 12px;
    }
}

@media (max-width: 768px) {
    .admin-users-card-enter-from,
    .admin-users-card-leave-to {
        transform: translateY(20px);
    }
}

@media (max-width: 640px) {
    .admin-users__header {
        padding-top: 20px;
    }

    .admin-users__header-actions {
        top: 14px;
        right: 12px;
    }

    .admin-users__dashboard-btn {
        font-size: 11px;
        padding: 6px 10px;
    }
}
</style>
