<template>
    <nav class="bottom-nav" data-onboarding="home-bottom-nav" :class="{ 'onboarding-locked': onboardingState.mode }" aria-label="Primary">
        <div class="bottom-nav-inner">
            <router-link class="nav-item" :to="{ name: 'home' }" active-class="" exact-active-class="active">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path d="M3 11.5 12 4l9 7.5" />
                        <path d="M5 10.5V20h14v-9.5" />
                        <path d="M9.5 20v-6h5v6" />
                    </svg>
                </span>
                <span class="nav-label">Home</span>
            </router-link>

            <router-link class="nav-item" :to="{ name: 'scan' }" active-class="active">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                        <rect x="4" y="6" width="16" height="12" rx="3" />
                        <circle cx="12" cy="12" r="3.5" />
                        <path d="M8.5 6 10 4.5h4L15.5 6" />
                    </svg>
                </span>
                <span class="nav-label">Scan</span>
            </router-link>

            <router-link class="nav-item" :to="{ name: 'insights' }" active-class="active">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path d="M4 18h16" />
                        <path d="M7 18V9" />
                        <path d="M12 18V6" />
                        <path d="M17 18v-4" />
                    </svg>
                </span>
                <span class="nav-label">Insights</span>
            </router-link>

            <router-link class="nav-item" :to="{ name: 'chat' }" active-class="active">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path d="M4 6h16v9a3 3 0 0 1-3 3H9l-5 4V6z" />
                    </svg>
                </span>
                <span class="nav-label">Chat</span>
            </router-link>

            <router-link class="nav-item" :to="{ name: 'savings' }" active-class="active">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path d="M12 4c3 0 5.5 2.5 5.5 5.5S15 20 12 20 6.5 17.5 6.5 14" />
                        <path d="M6 8.5C6 6 8 4 10.5 4" />
                        <path d="M8.5 12h7" />
                    </svg>
                </span>
                <span class="nav-label">Savings</span>
            </router-link>

        </div>
    </nav>
</template>

<script setup>
import { onMounted } from 'vue';
import { ensureBillingStatus } from '../stores/billing';
import { onboardingState } from '../stores/onboarding';

onMounted(() => {
    ensureBillingStatus();
});
</script>
