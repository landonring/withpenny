<template>
    <section class="marketing pricing">
        <header class="hero pricing-hero">
            <div class="hero-copy">
                <p class="eyebrow">Pricing</p>
                <h1 class="hero-title">Choose the pace that feels right.</h1>
                <p class="hero-sub">
                    Penny works whether you want to track manually or invite a little help.
                </p>
                <div class="hero-actions">
                    <router-link class="primary-button" :to="{ name: 'register' }">Get started</router-link>
                    <router-link class="ghost-button" :to="{ name: 'marketing' }">Back to overview</router-link>
                </div>
                <p class="hero-note">No pressure. You can switch plans anytime.</p>
            </div>
            <div class="hero-card card">
                <div class="card-title">Calm, honest pricing</div>
                <p class="card-sub">
                    Pick what feels supportive today. You can move up or down whenever you want.
                </p>
                <div class="hero-points">
                    <div class="hero-point">Starter stays free, forever.</div>
                    <div class="hero-point">Paid plans keep things gentle and optional.</div>
                    <div class="hero-point">Your data always stays yours.</div>
                </div>
            </div>
        </header>

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

        <section class="pricing-cards">
            <article class="card pricing-card">
                <header>
                    <p class="eyebrow">Starter — Free</p>
                    <h2 class="section-title">{{ starterPrice }} <span class="price-unit">{{ billingUnit }}</span></h2>
                    <p class="section-copy">A quiet place to start.</p>
                </header>
                <ul class="pricing-list">
                    <li>Access to every feature in Penny</li>
                    <li>Receipt scanning: 5 scans / month (basic extraction)</li>
                    <li>Bank statements: 2 uploads / month, up to 30 days each</li>
                    <li>Insights: 2 weekly + 1 monthly per month</li>
                    <li>Chat: 10 messages / month (basic context)</li>
                    <li>Manual tracking and offline support</li>
                </ul>
                <p class="pricing-note">Great for getting started with calm, usage-based limits.</p>
                <button class="primary-button wide" type="button" @click="handleStarter">Start free</button>
            </article>

            <article class="card pricing-card recommended">
                <header>
                    <p class="eyebrow">Pro</p>
                    <h2 class="section-title">{{ proPrice }} <span class="price-unit">{{ billingUnit }}</span></h2>
                    <p class="section-copy">A little guidance goes a long way.</p>
                </header>
                <ul class="pricing-list">
                    <li>Everything in Starter</li>
                    <li>Receipt scanning: 20 scans / month + editable categories</li>
                    <li>Bank statements: 10 uploads / month, up to 6 months each</li>
                    <li>Insights: 10 daily / month</li>
                    <li>Insights: unlimited weekly check-ins</li>
                    <li>Insights: 4 monthly overviews / month</li>
                    <li>Yearly overview (1 per year)</li>
                    <li>Chat: 25 messages / month</li>
                </ul>
                <p class="pricing-note">All features stay visible. Limits simply expand.</p>
                <button
                    class="primary-button wide"
                    type="button"
                    :disabled="billingBusy === 'pro'"
                    @click="startPlan('pro')"
                >
                    {{ billingBusy === 'pro' ? 'Starting…' : 'Upgrade to Pro' }}
                </button>
            </article>

            <article class="card pricing-card">
                <header>
                    <p class="eyebrow">Premium</p>
                    <h2 class="section-title">{{ premiumPrice }} <span class="price-unit">{{ billingUnit }}</span></h2>
                    <p class="section-copy">For those who want Penny fully by their side.</p>
                </header>
                <ul class="pricing-list">
                    <li>Everything in Pro</li>
                    <li>Unlimited receipt scanning</li>
                    <li>Unlimited bank statement uploads</li>
                    <li>Unlimited daily / weekly / monthly / yearly insights</li>
                    <li>Unlimited AI chat</li>
                    <li>No counters, ceilings, or limit reminders</li>
                </ul>
                <p class="pricing-note">Full support, zero pressure.</p>
                <button
                    class="primary-button wide"
                    type="button"
                    :disabled="billingBusy === 'premium'"
                    @click="startPlan('premium')"
                >
                    {{ billingBusy === 'premium' ? 'Starting…' : 'Go Premium' }}
                </button>
            </article>
        </section>
        <p v-if="billingError" class="form-error">{{ billingError }}</p>

        <section class="card marketing-section pricing-compare">
            <h2 class="section-title">A quick comparison</h2>
            <div class="compare-grid">
                <div class="compare-row compare-head">
                    <span></span>
                    <span>Starter</span>
                    <span>Pro</span>
                    <span>Premium</span>
                </div>
                <div class="compare-row">
                    <span>Feature visibility</span>
                    <span>All features visible</span>
                    <span>All features visible</span>
                    <span>All features visible</span>
                </div>
                <div class="compare-row">
                    <span>Manual tracking</span>
                    <span>Included</span>
                    <span>Included</span>
                    <span>Included</span>
                </div>
                <div class="compare-row">
                    <span>Receipt scanning</span>
                    <span>5 / month (basic)</span>
                    <span>20 / month (full)</span>
                    <span>Unlimited</span>
                </div>
                <div class="compare-row">
                    <span>Bank statements</span>
                    <span>2 / month, 30-day window</span>
                    <span>10 / month, 6-month window</span>
                    <span>Unlimited</span>
                </div>
                <div class="compare-row">
                    <span>Insights (daily)</span>
                    <span>Not included</span>
                    <span>10 / month</span>
                    <span>Unlimited</span>
                </div>
                <div class="compare-row">
                    <span>Insights (weekly)</span>
                    <span>2 / month</span>
                    <span>Unlimited</span>
                    <span>Unlimited</span>
                </div>
                <div class="compare-row">
                    <span>Insights (monthly)</span>
                    <span>1 / month</span>
                    <span>4 / month</span>
                    <span>Unlimited</span>
                </div>
                <div class="compare-row">
                    <span>Insights (yearly)</span>
                    <span>Not included</span>
                    <span>1 / year</span>
                    <span>Unlimited</span>
                </div>
                <div class="compare-row">
                    <span>AI chat</span>
                    <span>10 / month (basic)</span>
                    <span>25 / month</span>
                    <span>Unlimited</span>
                </div>
                <div class="compare-row">
                    <span>Offline support</span>
                    <span>Included</span>
                    <span>Included</span>
                    <span>Included</span>
                </div>
            </div>
        </section>

        <section class="card marketing-section">
            <h2 class="section-title">A calm promise</h2>
            <p class="section-copy">You can switch plans anytime.</p>
            <p class="section-copy">Your data stays yours, no matter what you choose.</p>
            <p class="section-copy">No pressure. No guilt. Only clarity.</p>
        </section>

        <section class="card marketing-section" id="pricing-faq">
            <h2 class="section-title">Pricing FAQ</h2>
            <div class="faq-list">
                <details>
                    <summary>Is Starter really free?</summary>
                    <p>Yes. Starter stays free, and you can take as long as you need.</p>
                </details>
                <details>
                    <summary>Can I upgrade later?</summary>
                    <p>Anytime. You can move up whenever it feels right.</p>
                </details>
                <details>
                    <summary>What happens if I hit a limit?</summary>
                    <p>You can still open every feature. Penny gently pauses only the action until the period resets or you upgrade.</p>
                </details>
                <details>
                    <summary>Do I lose my data?</summary>
                    <p>No. Your data is always yours, across every plan.</p>
                </details>
                <details>
                    <summary>Can I cancel anytime?</summary>
                    <p>Yes. You’re never locked in.</p>
                </details>
            </div>
        </section>

        <section class="final-cta card">
            <h2 class="section-title">Start with one small step.</h2>
            <router-link class="primary-button wide" :to="{ name: 'register' }">Get Penny</router-link>
            <p class="section-copy">No pressure. Just clarity.</p>
        </section>
    </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { authState } from '../stores/auth';
import { startCheckout } from '../stores/billing';

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
</script>
