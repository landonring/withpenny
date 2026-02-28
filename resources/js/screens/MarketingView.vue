<template>
    <section class="marketing">
        <div class="marketing-inner">
        <header class="hero hero-centered">
            <div class="hero-copy hero-stack">
                <p class="eyebrow">Penny</p>
                <h1 class="hero-title">Penny — your calm money companion</h1>
                <p class="hero-sub">
                    A gentle way to track spending, understand your habits, and save without stress.
                </p>
                <div class="hero-actions">
                    <router-link v-if="!isDesktop" class="primary-button" :to="{ name: 'home' }">Get Penny</router-link>
                    <button v-else type="button" class="primary-button" @click="scrollToInstall">Get Penny</button>
                    <button type="button" class="ghost-button" @click="scrollToHow">How it works</button>
                </div>
                <div class="hero-cues" aria-hidden="true">
                    <span class="hero-cue">No pressure</span>
                    <span class="hero-cue">Offline friendly</span>
                    <span class="hero-cue">Private by design</span>
                </div>
                <p class="hero-note">Mobile-first. Desktop supported for viewing.</p>
            </div>
            <div class="hero-scroll" aria-hidden="true">
                <span class="scroll-dot"></span>
            </div>
        </header>

        <section class="marketing-section why-section" id="why">
            <h2 class="section-title">Why Penny</h2>
            <p class="section-copy">
                Penny is built for people who feel stressed about money. It gives you a quiet place to
                look at what’s real — without judgment, pressure, or shame.
            </p>
            <p class="section-copy">
                It’s designed for small moments: a purchase, a paycheck, a gentle check-in when you
                feel ready.
            </p>
        </section>

        <section class="marketing-section marketing-grid" id="how">
            <div class="marketing-section">
                <h2 class="section-title">How it works</h2>
                <ul class="step-list">
                    <li>Add spending or income moments on your phone.</li>
                    <li>See patterns gently, without overwhelm.</li>
                    <li>Save without pressure.</li>
                    <li>Talk with Penny AI for calm guidance.</li>
                </ul>
            </div>
            <div class="marketing-section">
                <h2 class="section-title">Mobile-first, on purpose</h2>
                <p class="section-copy">
                    The full experience lives on your phone. Desktop is supported for viewing only.
                </p>
                <p class="section-copy">
                    This is intentional — Penny is meant for quick moments, camera capture, and
                    habit‑building on the go.
                </p>
            </div>
        </section>

        <section class="marketing-section soft-section" id="offline">
            <h2 class="section-title">Offline support</h2>
            <p class="section-copy">
                Penny works even without service. Add spending, scan receipts, and review history.
                Everything syncs when you’re back online.
            </p>
            <div class="pill-row">
                <span class="pill">Installs like an app</span>
                <span class="pill">No app store needed</span>
                <span class="pill">Fast and lightweight</span>
            </div>
        </section>

        <section class="marketing-section" id="install">
            <h2 class="section-title">Install Penny (PWA)</h2>
            <div class="install-grid">
                <div class="install-card">
                    <h3>iPhone (Safari)</h3>
                    <ol>
                        <li>Open Penny in Safari</li>
                        <li>Tap Share</li>
                        <li>Tap “Add to Home Screen”</li>
                    </ol>
                </div>
                <div class="install-card">
                    <h3>Android (Chrome)</h3>
                    <ol>
                        <li>Open Penny in Chrome</li>
                        <li>Tap the menu</li>
                        <li>Tap “Install App”</li>
                    </ol>
                </div>
            </div>
            <p class="section-copy">Installing Penny unlocks the full experience.</p>
        </section>

        <section class="marketing-section" id="pricing">
            <h2 class="section-title">Pricing</h2>
            <p class="section-copy">
                Choose the pace that feels right. Starter is free. Pro and Premium add gentle support.
            </p>
            <div class="pricing-toggle">
                <div class="billing-switch" :class="{ 'is-annual': billingCycle === 'yearly' }">
                    <button
                        type="button"
                        class="billing-label"
                        :class="{ active: billingCycle === 'monthly' }"
                        @click="billingCycle = 'monthly'"
                    >
                        Monthly
                    </button>
                    <button
                        type="button"
                        class="billing-toggle"
                        :aria-pressed="billingCycle === 'yearly'"
                        @click="billingCycle = billingCycle === 'monthly' ? 'yearly' : 'monthly'"
                    >
                        <span class="billing-knob" aria-hidden="true"></span>
                    </button>
                    <button
                        type="button"
                        class="billing-label"
                        :class="{ active: billingCycle === 'yearly' }"
                        @click="billingCycle = 'yearly'"
                    >
                        Yearly <span class="billing-save">save 10%</span>
                    </button>
                </div>
            </div>
            <div class="pricing-cards">
                <div class="card pricing-card">
                    <p class="eyebrow">Starter — Free</p>
                    <h3 class="section-title">{{ starterPrice }} <span class="price-unit">{{ billingUnit }}</span></h3>
                    <p class="section-copy">A quiet place to start.</p>
                    <ul class="pricing-list">
                        <li>All features visible</li>
                        <li>5 receipt scans / month</li>
                        <li>2 statement uploads / month</li>
                        <li>2 weekly + 1 monthly insights / month</li>
                        <li>10 chat messages / month</li>
                    </ul>
                    <button class="primary-button wide" type="button" @click="handleStarter">
                        Start free
                    </button>
                </div>
                <div class="card pricing-card">
                    <p class="eyebrow">Pro</p>
                    <h3 class="section-title">{{ proPrice }} <span class="price-unit">{{ billingUnit }}</span></h3>
                    <p class="section-copy">A little guidance goes a long way.</p>
                    <ul class="pricing-list">
                        <li>20 receipt scans / month</li>
                        <li>10 statement uploads / month</li>
                        <li>10 daily, unlimited weekly, 4 monthly, 1 yearly insights</li>
                        <li>25 chat messages / month</li>
                    </ul>
                    <button
                        class="primary-button wide"
                        type="button"
                        :disabled="billingBusy === 'pro'"
                        @click="startPlan('pro')"
                    >
                        {{ billingBusy === 'pro' ? 'Starting…' : 'Choose Pro' }}
                    </button>
                </div>
                <div class="card pricing-card">
                    <p class="eyebrow">Premium</p>
                    <h3 class="section-title">{{ premiumPrice }} <span class="price-unit">{{ billingUnit }}</span></h3>
                    <p class="section-copy">Full support, zero pressure.</p>
                    <ul class="pricing-list">
                        <li>Unlimited receipt scanning</li>
                        <li>Unlimited statement uploads</li>
                        <li>Unlimited insights</li>
                        <li>Unlimited AI chat</li>
                        <li>All Pro features</li>
                    </ul>
                    <button
                        class="primary-button wide"
                        type="button"
                        :disabled="billingBusy === 'premium'"
                        @click="startPlan('premium')"
                    >
                        {{ billingBusy === 'premium' ? 'Starting…' : 'Choose Premium' }}
                    </button>
                </div>
            </div>
            <p v-if="billingError" class="form-error">{{ billingError }}</p>
        </section>

        <section class="marketing-section" id="faq">
            <h2 class="section-title">FAQ</h2>
            <div class="faq-list">
                <div v-for="(item, index) in faqItems" :key="item.q" class="faq-item">
                    <button
                        type="button"
                        class="faq-question"
                        :aria-expanded="openFaq === index"
                        @click="setFaq(index)"
                    >
                        <span>{{ item.q }}</span>
                        <span class="faq-icon" aria-hidden="true">{{ openFaq === index ? '–' : '+' }}</span>
                    </button>
                    <p v-if="openFaq === index" class="faq-answer">{{ item.a }}</p>
                </div>
            </div>
        </section>

        <footer class="marketing-footer">
            <div>
                <p class="section-copy">Privacy-first by design. No ads. No pressure.</p>
                <p class="section-copy">Penny is here when you’re ready.</p>
            </div>
        </footer>
        </div>
    </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { authState } from '../stores/auth';
import { startCheckout } from '../stores/billing';

const isDesktop = typeof window !== 'undefined' && window.__PENNY_DESKTOP__ === true;

const faqItems = [
    { q: 'Is Penny free?', a: 'Yes. Starter is free and stays that way. You can upgrade any time.' },
    { q: 'Is my data safe?', a: 'Yes. Your data stays yours. Penny doesn’t sell it or run ads.' },
    { q: 'Do I need to link a bank?', a: 'No. Penny works fully without bank connections.' },
    { q: 'Does Penny judge me?', a: 'No. Penny is built to be calm, kind, and honest — never shaming.' },
    { q: 'Can I use it offline?', a: 'Yes. You can keep going offline and it syncs when you’re back.' },
];

const openFaq = ref(0);
const setFaq = (index) => {
    openFaq.value = openFaq.value === index ? -1 : index;
};

const billingCycle = ref('monthly');
const billingUnit = computed(() => (billingCycle.value === 'monthly' ? '/ month' : '/ year'));
const billingBusy = ref('');
const billingError = ref('');

const formatPrice = (value) => {
    const rounded = Math.round(value * 100) / 100;
    const whole = Math.abs(rounded - Math.round(rounded)) < 0.001;
    return `$${whole ? Math.round(rounded) : rounded.toFixed(2)}`;
};

const starterPrice = computed(() => formatPrice(0));
const proPrice = computed(() => {
    if (billingCycle.value === 'monthly') return formatPrice(15);
    return formatPrice(15 * 12 * 0.9);
});
const premiumPrice = computed(() => {
    if (billingCycle.value === 'monthly') return formatPrice(25);
    return formatPrice(25 * 12 * 0.9);
});

const router = useRouter();
const route = useRoute();

const handleStarter = () => {
    if (authState.user) {
        router.push({ name: 'home' });
        return;
    }
    router.push({ name: 'register', query: { redirect: route.fullPath } });
};

const startPlan = async (plan) => {
    billingError.value = '';

    if (!authState.user) {
        router.push({
            name: 'register',
            query: {
                plan,
                interval: billingCycle.value,
                redirect: route.fullPath,
            },
        });
        return;
    }

    billingBusy.value = plan;

    try {
        const data = await startCheckout(plan, billingCycle.value);
        if (data?.url) {
            window.location.href = data.url;
            return;
        }
        router.push({ name: 'home' });
    } catch (err) {
        billingError.value = err?.response?.data?.message || 'Unable to start billing right now.';
    } finally {
        billingBusy.value = '';
    }
};

const scrollToHow = () => {
    if (typeof document === 'undefined') return;
    const target = document.getElementById('how');
    if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
};

const scrollToInstall = () => {
    if (typeof document === 'undefined') return;
    const target = document.getElementById('install');
    if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
};
</script>
