<template>
    <div class="app-shell">
        <main :class="['main-content', { 'lock-scroll': route.meta.lockScroll }]">
            <router-view />
        </main>

        <BottomNav v-if="!route.meta.hideNav" />
    </div>
</template>

<script setup>
import { useRoute } from 'vue-router';
import BottomNav from './BottomNav.vue';

const route = useRoute();
</script>
