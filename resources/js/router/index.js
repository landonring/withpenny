import { createRouter, createWebHistory } from 'vue-router';
import HomeView from '../screens/HomeView.vue';
import ScanView from '../screens/ScanView.vue';
import InsightsView from '../screens/InsightsView.vue';
import ChatView from '../screens/ChatView.vue';
import SavingsView from '../screens/SavingsView.vue';
import LoginView from '../screens/LoginView.vue';
import SignupView from '../screens/SignupView.vue';
import { authState, ensureAuthReady } from '../stores/auth';

const routes = [
    { path: '/', name: 'home', component: HomeView, meta: { requiresAuth: true } },
    { path: '/scan', name: 'scan', component: ScanView, meta: { requiresAuth: true } },
    { path: '/insights', name: 'insights', component: InsightsView, meta: { requiresAuth: true } },
    { path: '/chat', name: 'chat', component: ChatView, meta: { requiresAuth: true } },
    { path: '/savings', name: 'savings', component: SavingsView, meta: { requiresAuth: true } },
    { path: '/login', name: 'login', component: LoginView, meta: { guestOnly: true, hideNav: true } },
    { path: '/register', name: 'register', component: SignupView, meta: { guestOnly: true, hideNav: true } },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach(async (to) => {
    await ensureAuthReady();

    if (to.meta.requiresAuth && !authState.user) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    if (to.meta.guestOnly && authState.user) {
        return { name: 'home' };
    }

    return true;
});

export default router;
