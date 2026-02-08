<template>
    <section class="screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">This month</p>
                <h1 class="screen-title">A calm look at your money, {{ displayName }}</h1>
            </div>
            <div class="accent-chip">Overview</div>
        </div>

        <div class="card overview-card">
            <div class="card-title">Monthly overview</div>
            <p class="card-sub">A gentle snapshot of your balance and flow.</p>
            <div class="soft-chart">
                <div class="soft-bar needs">
                    <span>Needs</span>
                </div>
                <div class="soft-bar wants">
                    <span>Wants</span>
                </div>
                <div class="soft-bar future">
                    <span>Future</span>
                </div>
            </div>
            <p class="muted">We will show simple, friendly charts here soon.</p>
        </div>

        <div class="card action-card">
            <div>
                <div class="card-title">Add a spending moment</div>
                <p class="card-sub">Quick notes now, details later.</p>
            </div>
            <button class="primary-button" type="button">Add spending</button>
        </div>

        <div class="card calm-note">
            <p class="soft-quote">
                You are not behind. You are learning, one calm step at a time.
            </p>
        </div>
    </section>
</template>

<script setup>
import { computed } from 'vue';
import { authState } from '../stores/auth';

const displayName = computed(() => {
    const name = authState.user?.name || '';
    return name.split(' ')[0] || 'there';
});
</script>
