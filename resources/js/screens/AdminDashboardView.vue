<template>
    <section class="admin-dashboard admin-dashboard--revamp">
        <main class="dashboard-container">
            <header class="dashboard-header">
                <h1 class="dashboard-title">Penny Health</h1>
                <div class="dashboard-actions">
                    <button class="dashboard-ghost" type="button" @click="goToUsers">
                        Users
                    </button>
                    <button class="dashboard-ghost" type="button" @click="goToUpdates">
                        Roadmap &amp; Feedback
                    </button>
                    <button class="dashboard-refresh" type="button" :disabled="loading" @click="loadAll">
                        {{ loading ? 'Refreshing…' : 'Refresh' }}
                    </button>
                </div>
            </header>

            <p v-if="error" class="form-error">{{ error }}</p>

            <section class="dashboard-kpis">
                <div class="dashboard-card kpi-tile">
                    <span class="kpi-number">{{ formatNumber(overview?.total_users) }}</span>
                    <span class="kpi-caption">Total Users</span>
                </div>
                <div class="dashboard-card kpi-tile">
                    <span class="kpi-number">{{ formatNumber(overview?.active_users_7d) }}</span>
                    <span class="kpi-caption">Active (7D)</span>
                </div>
                <div class="dashboard-card kpi-tile">
                    <span class="kpi-number">{{ formatNumber(overview?.paying_users) }}</span>
                    <span class="kpi-caption">Paying Users</span>
                </div>
                <div class="dashboard-card kpi-tile kpi-tile--percent">
                    <span class="kpi-number">{{ formatPercent(overview?.churn_rate) }}</span>
                    <span class="kpi-caption">Churn %</span>
                </div>
            </section>

            <section class="dashboard-card chart-card">
                <div class="card-heading">
                    <h2>Weekly Signups</h2>
                    <p>A steady flow of new accounts.</p>
                </div>
                <div class="chart-frame">
                    <div class="chart-y-axis">
                        <span>{{ lineMaxLabel }}</span>
                        <span>{{ lineMidLabel }}</span>
                        <span>0</span>
                    </div>
                    <div class="chart-plot">
                        <svg
                            :viewBox="`0 0 ${chartWidth} ${chartHeight}`"
                            preserveAspectRatio="none"
                            @mousemove="handleChartHover"
                            @mouseleave="hoveredPointIndex = null"
                        >
                            <line class="chart-grid-line" :x1="chartLeft" :y1="chartTop" :x2="chartRight" :y2="chartTop" />
                            <line class="chart-grid-line" :x1="chartLeft" :y1="chartMidY" :x2="chartRight" :y2="chartMidY" />
                            <line class="chart-grid-line" :x1="chartLeft" :y1="chartBottom" :x2="chartRight" :y2="chartBottom" />
                            <path v-if="lineAreaPath" class="chart-area" :d="lineAreaPath" />
                            <path v-if="linePath" class="chart-line" :d="linePath" />
                            <g
                                v-for="(point, index) in linePointDots"
                                :key="index"
                            >
                                <circle
                                    class="chart-point-hit"
                                    :cx="point.x"
                                    :cy="point.y"
                                    r="10"
                                    tabindex="0"
                                    role="img"
                                    :aria-label="`Signups on ${point.date}: ${point.count}`"
                                    @mouseenter="hoveredPointIndex = index"
                                    @focus="hoveredPointIndex = index"
                                    @blur="hoveredPointIndex = null"
                                />
                                <circle
                                    class="chart-point"
                                    :class="{ 'is-active': hoveredPointIndex === index }"
                                    :cx="point.x"
                                    :cy="point.y"
                                    :r="hoveredPointIndex === index ? 5 : 4"
                                />
                            </g>
                            <g v-if="hoveredTooltip" class="chart-tooltip" :transform="`translate(${hoveredTooltip.x}, ${hoveredTooltip.y})`">
                                <rect class="chart-tooltip-box" :width="hoveredTooltip.width" :height="hoveredTooltip.height" rx="10" ry="10" />
                                <text class="chart-tooltip-title" x="10" y="16">{{ hoveredTooltip.date }}</text>
                                <text class="chart-tooltip-value" x="10" y="32">{{ hoveredTooltip.count }} signups</text>
                            </g>
                        </svg>
                        <div class="chart-x-axis">
                            <span>{{ lineStartLabel }}</span>
                            <span>{{ lineEndLabel }}</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="dashboard-grid">
                <article class="dashboard-card breakdown-card">
                    <div>
                        <h2>Plan Breakdown</h2>
                        <p class="card-sub">Current distribution by plan</p>
                    </div>
                    <div class="plan-rows">
                        <div class="plan-row">
                            <span>Free</span>
                            <div class="plan-bar">
                                <div class="plan-fill free" :style="{ width: planPercent('free') }"></div>
                            </div>
                            <span class="plan-count">{{ formatNumber(overview?.free_users) }}</span>
                        </div>
                        <div class="plan-row">
                            <span>Pro</span>
                            <div class="plan-bar">
                                <div class="plan-fill pro" :style="{ width: planPercent('pro') }"></div>
                            </div>
                            <span class="plan-count">{{ formatNumber(overview?.pro_users) }}</span>
                        </div>
                        <div class="plan-row">
                            <span>Premium</span>
                            <div class="plan-bar">
                                <div class="plan-fill premium" :style="{ width: planPercent('premium') }"></div>
                            </div>
                            <span class="plan-count">{{ formatNumber(overview?.premium_users) }}</span>
                        </div>
                    </div>
                    <div class="plan-footer">
                        <span>MRR</span>
                        <strong>${{ formatNumber(overview?.mrr, true) }}</strong>
                    </div>
                </article>

                <article class="dashboard-card usage-card">
                    <div>
                        <h2>Feature Usage</h2>
                        <p class="card-sub">Simple, high-level counts.</p>
                    </div>
                    <div class="usage-grid">
                        <div>
                            <span class="usage-number">{{ formatNumber(featureUsage?.receipt_uploaded) }}</span>
                            <span class="usage-label">Receipt Uploads</span>
                        </div>
                        <div>
                            <span class="usage-number">{{ formatNumber(featureUsage?.reflection_generated) }}</span>
                            <span class="usage-label">Reflections Generated</span>
                        </div>
                        <div>
                            <span class="usage-number">{{ formatNumber(featureUsage?.life_phase_selected) }}</span>
                            <span class="usage-label">Life Phase Selections</span>
                        </div>
                    </div>
                </article>
            </section>

            <section class="dashboard-card table-card">
                <div class="table-heading">
                    <div>
                        <h2>User List</h2>
                        <p class="card-sub">Search, filter, and scan without the noise.</p>
                    </div>
                    <div class="table-search">
                        <span>
                            <svg viewBox="0 0 20 20" aria-hidden="true">
                                <path
                                    d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z"
                                    fill="currentColor"
                                />
                            </svg>
                        </span>
                        <input v-model="search" type="search" placeholder="Search name or email" />
                    </div>
                </div>

                <div v-if="tableError" class="form-error">{{ tableError }}</div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th @click="sortBy('name')">Name</th>
                                <th @click="sortBy('email')">Email</th>
                                <th>Plan</th>
                                <th @click="sortBy('life_phase')">Life Phase</th>
                                <th @click="sortBy('last_login_at')">Last Login</th>
                                <th @click="sortBy('created_at')">Signup Date</th>
                                <th @click="sortBy('subscription_status')">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="user in users.data" :key="user.id">
                                <td>{{ user.name }}</td>
                                <td>{{ user.email }}</td>
                                <td>{{ planLabel(user.plan) }}</td>
                                <td>{{ lifePhaseLabel(user.life_phase) }}</td>
                                <td>{{ formatDate(user.last_login) }}</td>
                                <td>{{ formatDate(user.created_at) }}</td>
                                <td>{{ statusLabel(user.subscription_status) }}</td>
                            </tr>
                            <tr v-if="!users.data.length && !tableLoading">
                                <td colspan="7" class="table-empty">No results yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </section>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';

const router = useRouter();

const overview = ref(null);
const growth = ref({
    signups_by_date: [],
    upgrades_by_date: [],
    cancellations_by_date: [],
});
const featureUsage = ref({
    receipt_uploaded: 0,
    reflection_generated: 0,
    life_phase_selected: 0,
});
const loading = ref(true);
const error = ref('');

const users = ref({ data: [], last_page: 1 });
const page = ref(1);
const search = ref('');
const planFilter = ref('');
const sort = ref('created_at');
const direction = ref('desc');
const tableLoading = ref(false);
const tableError = ref('');
let searchTimer = null;

const loadAll = async () => {
    loading.value = true;
    error.value = '';

    try {
        const [overviewRes, growthRes, featureRes] = await Promise.all([
            axios.get('/admin/analytics/overview'),
            axios.get('/admin/analytics/growth'),
            axios.get('/admin/analytics/feature-usage'),
        ]);
        overview.value = overviewRes.data;
        growth.value = growthRes.data;
        featureUsage.value = featureRes.data;
        await loadUsers();
    } catch (err) {
        if (err?.response?.status === 403) {
            router.push('/app');
            return;
        }
        error.value = 'Unable to load analytics right now.';
    } finally {
        loading.value = false;
    }
};

const loadUsers = async () => {
    tableLoading.value = true;
    tableError.value = '';

    try {
        const { data } = await axios.get('/admin/analytics/users', {
            params: {
                page: page.value,
                search: search.value,
                plan: planFilter.value,
                sort: sort.value,
                direction: direction.value,
            },
        });
        users.value = data;
    } catch (err) {
        if (err?.response?.status === 403) {
            router.push('/app');
            return;
        }
        tableError.value = 'Unable to load users right now.';
    } finally {
        tableLoading.value = false;
    }
};

const goToUsers = () => {
    router.push('/admin/users');
};

const goToUpdates = () => {
    router.push('/admin/roadmap-feedback');
};

const changePage = (nextPage) => {
    page.value = nextPage;
    loadUsers();
};

const sortBy = (field) => {
    if (sort.value === field) {
        direction.value = direction.value === 'asc' ? 'desc' : 'asc';
    } else {
        sort.value = field;
        direction.value = 'desc';
    }
    loadUsers();
};

watch([search, planFilter], () => {
    page.value = 1;
    if (searchTimer) window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadUsers(), 300);
});

const chartWidth = 1000;
const chartHeight = 220;
const chartPadding = { top: 12, right: 10, bottom: 10, left: 10 };
const hoveredPointIndex = ref(null);

const chartLeft = chartPadding.left;
const chartRight = chartWidth - chartPadding.right;
const chartTop = chartPadding.top;
const chartBottom = chartHeight - chartPadding.bottom;
const chartMidY = chartTop + (chartBottom - chartTop) / 2;
const chartUsableWidth = chartRight - chartLeft;
const chartUsableHeight = chartBottom - chartTop;

const linePointDots = computed(() => {
    const points = growth.value?.signups_by_date || [];
    if (!points.length) return [];
    const max = Math.max(...points.map((p) => p.count), 1);
    const step = chartUsableWidth / (points.length - 1 || 1);
    return points.map((point, index) => ({
        x: chartLeft + index * step,
        y: chartBottom - (point.count / max) * chartUsableHeight,
        date: point.date,
        count: point.count ?? 0,
    }));
});

const linePath = computed(() => {
    if (!linePointDots.value.length) return '';
    return `M ${linePointDots.value.map((point) => `${point.x} ${point.y}`).join(' L ')}`;
});

const lineAreaPath = computed(() => {
    if (!linePointDots.value.length) return '';
    const start = linePointDots.value[0];
    const end = linePointDots.value[linePointDots.value.length - 1];
    return [
        `M ${start.x} ${chartBottom}`,
        `L ${start.x} ${start.y}`,
        ...linePointDots.value.slice(1).map((point) => `L ${point.x} ${point.y}`),
        `L ${end.x} ${chartBottom}`,
        'Z',
    ].join(' ');
});

const hoveredTooltip = computed(() => {
    const point = linePointDots.value[hoveredPointIndex.value ?? -1];
    if (!point) return null;

    const width = 156;
    const height = 40;
    const minX = chartLeft;
    const maxX = chartRight - width;
    const x = Math.min(maxX, Math.max(minX, point.x - width / 2));
    const y = Math.max(chartTop, point.y - height - 10);

    return {
        x,
        y,
        width,
        height,
        date: point.date,
        count: point.count,
    };
});

const handleChartHover = (event) => {
    if (!linePointDots.value.length) {
        hoveredPointIndex.value = null;
        return;
    }

    const rect = event.currentTarget?.getBoundingClientRect?.();
    if (!rect || !rect.width) {
        return;
    }

    const relativeX = event.clientX - rect.left;
    const x = (relativeX / rect.width) * chartWidth;

    let nearestIndex = 0;
    let nearestDistance = Infinity;
    for (let i = 0; i < linePointDots.value.length; i += 1) {
        const distance = Math.abs(linePointDots.value[i].x - x);
        if (distance < nearestDistance) {
            nearestDistance = distance;
            nearestIndex = i;
        }
    }

    hoveredPointIndex.value = nearestIndex;
};

const lineStartLabel = computed(() => {
    const points = growth.value?.signups_by_date || [];
    return points[0]?.date || '';
});

const lineEndLabel = computed(() => {
    const points = growth.value?.signups_by_date || [];
    return points[points.length - 1]?.date || '';
});

const lineEndCount = computed(() => {
    const points = growth.value?.signups_by_date || [];
    return points[points.length - 1]?.count ?? 0;
});

const lineMaxLabel = computed(() => {
    const points = growth.value?.signups_by_date || [];
    if (!points.length) return '0';
    return Math.max(...points.map((p) => p.count), 0);
});

const lineMidLabel = computed(() => {
    const max = Number(lineMaxLabel.value) || 0;
    return Math.round(max / 2);
});

const barMaxValue = computed(() => {
    const upgrades = growth.value?.upgrades_by_date || [];
    const cancellations = growth.value?.cancellations_by_date || [];
    const max = Math.max(
        ...upgrades.map((p) => p.count || 0),
        ...cancellations.map((p) => p.count || 0),
        0
    );
    return max || 1;
});

const planPercent = (plan) => {
    if (!overview.value) return '0%';
    const total = overview.value.total_users || 1;
    const value = plan === 'free'
        ? overview.value.free_users
        : plan === 'pro'
            ? overview.value.pro_users
            : overview.value.premium_users;
    return `${Math.round((value / total) * 100)}%`;
};

const formatNumber = (value, fixed = false) => {
    if (value === null || value === undefined) return '—';
    const num = Number(value);
    if (Number.isNaN(num)) return '—';
    return fixed ? num.toFixed(2) : num.toLocaleString();
};

const formatPercent = (value) => {
    if (value === null || value === undefined) return '—';
    const num = Number(value);
    if (Number.isNaN(num)) return '—';
    return `${num.toFixed(2)}%`;
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

const statusLabel = (status) => {
    if (!status || status === 'none') return 'Free';
    return status.replace('_', ' ');
};

const lifePhaseLabel = (phase) => {
    if (!phase) return '—';
    const labels = {
        early_builder: 'Early Builder',
        foundation: 'Foundation',
        stability: 'Stability',
        growth: 'Growth',
        consolidation: 'Consolidation',
        preservation: 'Preservation',
    };
    return labels[phase] || phase;
};

onMounted(() => {
    loadAll();
});
</script>
