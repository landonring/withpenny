import { createRouter, createWebHistory } from 'vue-router';
import HomeView from '../screens/HomeView.vue';
import MarketingView from '../screens/MarketingView.vue';
import PricingView from '../screens/PricingView.vue';
import ScanView from '../screens/ScanView.vue';
import InsightsView from '../screens/InsightsView.vue';
import ChatView from '../screens/ChatView.vue';
import SavingsView from '../screens/SavingsView.vue';
import SavingsCreateView from '../screens/SavingsCreateView.vue';
import SavingsAddView from '../screens/SavingsAddView.vue';
import SavingsEditView from '../screens/SavingsEditView.vue';
import StatementsView from '../screens/StatementsView.vue';
import StatementScanView from '../screens/StatementScanView.vue';
import StatementReviewView from '../screens/StatementReviewView.vue';
import TransactionsView from '../screens/TransactionsView.vue';
import AddSpendingView from '../screens/AddSpendingView.vue';
import EditTransactionView from '../screens/EditTransactionView.vue';
import ReceiptReviewView from '../screens/ReceiptReviewView.vue';
import ProfileView from '../screens/ProfileView.vue';
import NotFoundView from '../screens/NotFoundView.vue';
import LoginView from '../screens/LoginView.vue';
import SignupView from '../screens/SignupView.vue';
import { authState, ensureAuthReady } from '../stores/auth';

const routes = [
    {
        path: '/',
        name: 'marketing',
        component: MarketingView,
        meta: { guestOnly: true, hideNav: true, marketing: true },
    },
    {
        path: '/pricing',
        name: 'pricing',
        component: PricingView,
        meta: { guestOnly: true, hideNav: true, marketing: true },
    },
    { path: '/app', name: 'home', component: HomeView, meta: { requiresAuth: true, scrollable: true } },
    { path: '/scan', name: 'scan', component: ScanView, meta: { requiresAuth: true, hideHeader: true } },
    { path: '/insights', name: 'insights', component: InsightsView, meta: { requiresAuth: true } },
    { path: '/chat', name: 'chat', component: ChatView, meta: { requiresAuth: true, lockScroll: true } },
    { path: '/savings', name: 'savings', component: SavingsView, meta: { requiresAuth: true } },
    { path: '/savings/new', name: 'savings-new', component: SavingsCreateView, meta: { requiresAuth: true, scrollable: true } },
    { path: '/savings/:id/add', name: 'savings-add', component: SavingsAddView, meta: { requiresAuth: true, scrollable: true } },
    { path: '/savings/:id/edit', name: 'savings-edit', component: SavingsEditView, meta: { requiresAuth: true, scrollable: true } },
    { path: '/statements', name: 'statements', component: StatementsView, meta: { requiresAuth: true } },
    { path: '/statements/scan', name: 'statements-scan', component: StatementScanView, meta: { requiresAuth: true } },
    { path: '/statements/:id/review', name: 'statements-review', component: StatementReviewView, meta: { requiresAuth: true, scrollable: true } },
    { path: '/transactions', name: 'transactions', component: TransactionsView, meta: { requiresAuth: true } },
    { path: '/transactions/new', name: 'transactions-new', component: AddSpendingView, meta: { requiresAuth: true } },
    { path: '/transactions/:id/edit', name: 'transactions-edit', component: EditTransactionView, meta: { requiresAuth: true } },
    { path: '/profile', name: 'profile', component: ProfileView, meta: { requiresAuth: true, scrollable: true } },
    { path: '/scan/review/:id', name: 'receipts-review', component: ReceiptReviewView, meta: { requiresAuth: true, hideNav: true, hideHeader: true } },
    { path: '/login', name: 'login', component: LoginView, meta: { guestOnly: true, hideNav: true } },
    { path: '/register', name: 'register', component: SignupView, meta: { guestOnly: true, hideNav: true } },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundView, meta: { hideNav: true } },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach(async (to) => {
    const isDesktop = typeof window !== 'undefined' && window.__PENNY_DESKTOP__ === true;

    if (isDesktop && !to.meta?.marketing) {
        return { name: 'marketing' };
    }

    await ensureAuthReady();

    if (isDesktop) {
        return true;
    }

    if (to.meta.requiresAuth && !authState.user) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    if (to.meta.guestOnly && authState.user) {
        return { name: 'home' };
    }

    return true;
});

export default router;
