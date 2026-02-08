<template>
    <div class="app-shell">
        <header class="top-bar">
            <div class="brand">
                <div class="brand-mark">P</div>
                <div class="brand-copy">
                    <div class="brand-title">Penny</div>
                    <div class="brand-sub">Your calm money companion</div>
                </div>
            </div>
            <div class="top-actions">
                <button v-if="authState.user" class="ghost-button" type="button" @click="handleLogout">
                    Log out
                </button>
            </div>
        </header>

        <main class="main-content">
            <router-view />
        </main>

        <BottomNav v-if="!route.meta.hideNav" />
    </div>
</template>

<script setup>
import { useRoute, useRouter } from 'vue-router';
import BottomNav from './BottomNav.vue';
import { authState, logout } from '../stores/auth';

const route = useRoute();
const router = useRouter();

const handleLogout = async () => {
    await logout();
    router.push({ name: 'login' });
};
</script>
