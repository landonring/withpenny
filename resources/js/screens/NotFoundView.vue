<template>
    <section class="screen not-found-page">
        <div class="screen-header not-found-head">
            <div class="headline-wrap">
                <p class="eyebrow">Lost & Found</p>
                <h1 class="screen-title">This page took a coffee break.</h1>
                <p class="card-sub">
                    We looked everywhere. It might be out buying oat milk.
                </p>
                <p class="muted">
                    No worries. Your data is safe and nothing was lost.
                </p>
                <div class="not-found-actions">
                    <router-link class="primary-button" to="/">Take me home</router-link>
                    <button class="ghost-button" type="button" @click="goBack">Go back</button>
                </div>
                <div class="quick-links" aria-label="Quick links">
                    <router-link class="quick-link" to="/app">Dashboard</router-link>
                    <router-link class="quick-link" to="/insights">Insights</router-link>
                    <router-link class="quick-link" to="/chat">Chat</router-link>
                </div>
            </div>
            <div class="accent-chip not-found-chip">404</div>
        </div>

        <div class="card not-found-card" aria-hidden="true">
            <div class="shape shape-lg"></div>
            <div class="shape shape-sm"></div>
            <div class="not-found-code">404</div>
            <p class="not-found-note">Wrong turn. Calmly rerouting.</p>
        </div>
    </section>
</template>

<script setup>
const goBack = () => {
    if (window.history.length > 1) {
        window.history.back();
        return;
    }
    window.location.assign('/');
};
</script>

<style scoped>
.not-found-page {
    min-height: calc(100vh - 180px);
    align-content: center;
    gap: 18px;
}

.not-found-head {
    align-items: stretch;
    gap: 18px;
}

.headline-wrap {
    max-width: 720px;
}

.headline-wrap .screen-title {
    font-size: clamp(28px, 4vw, 44px);
    line-height: 1.06;
    margin-bottom: 10px;
}

.headline-wrap .card-sub {
    margin-bottom: 6px;
}

.not-found-chip {
    align-self: flex-start;
    margin-top: 4px;
}

.not-found-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 16px;
}

.quick-links {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}

.quick-link {
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.04);
    border: 1px solid rgba(0, 0, 0, 0.06);
    color: var(--muted);
    font-size: 12px;
    padding: 7px 12px;
    transition: background 0.18s ease, color 0.18s ease;
}

.quick-link:hover {
    background: rgba(0, 0, 0, 0.08);
    color: var(--ink);
}

.not-found-card {
    position: relative;
    overflow: hidden;
    min-height: 200px;
    display: grid;
    place-items: center;
    background:
        radial-gradient(circle at 20% 25%, rgba(214, 207, 195, 0.45), transparent 52%),
        radial-gradient(circle at 85% 70%, rgba(214, 207, 195, 0.35), transparent 50%),
        linear-gradient(180deg, #fff 0%, #f6f3ed 100%);
}

.shape {
    position: absolute;
    border-radius: 999px;
    border: 1px solid rgba(111, 106, 99, 0.18);
}

.shape-lg {
    width: min(480px, 60vw);
    height: min(480px, 60vw);
    left: -120px;
    top: -260px;
}

.shape-sm {
    width: 170px;
    height: 170px;
    right: 60px;
    bottom: -74px;
}

.not-found-code {
    position: relative;
    z-index: 2;
    font-size: clamp(56px, 14vw, 112px);
    line-height: 1;
    letter-spacing: -0.04em;
    color: rgba(43, 43, 43, 0.9);
    font-weight: 600;
}

.not-found-note {
    position: absolute;
    z-index: 2;
    bottom: 24px;
    margin: 0;
    color: var(--muted);
    font-size: 13px;
}

@media (max-width: 720px) {
    .not-found-page {
        min-height: auto;
    }

    .not-found-chip {
        display: none;
    }

    .not-found-actions {
        display: grid;
        grid-template-columns: 1fr;
    }

    .not-found-actions :deep(.primary-button),
    .not-found-actions :deep(.ghost-button) {
        width: 100%;
    }

    .not-found-card {
        min-height: 170px;
    }

    .shape-sm {
        right: 14px;
    }
}
</style>
