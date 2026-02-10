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

        <section class="pricing-cards">
            <article class="card pricing-card">
                <header>
                    <p class="eyebrow">Starter</p>
                    <h2 class="section-title">$0</h2>
                    <p class="section-copy">A quiet place to start.</p>
                </header>
                <ul class="pricing-list">
                    <li>Manual spending entries</li>
                    <li>Manual income entries</li>
                    <li>Basic monthly overview</li>
                    <li>Offline usage</li>
                    <li>Core app features</li>
                </ul>
                <p class="pricing-note">AI insights, receipt scanning, and statements stay off by default.</p>
                <router-link class="primary-button wide" :to="{ name: 'register' }">Start free</router-link>
            </article>

            <article class="card pricing-card recommended">
                <header>
                    <p class="eyebrow">Pro</p>
                    <h2 class="section-title">$10 <span class="price-unit">/ month</span></h2>
                    <p class="section-copy">A little guidance goes a long way.</p>
                </header>
                <ul class="pricing-list">
                    <li>Everything in Starter</li>
                    <li>Weekly AI insight (1 per week)</li>
                    <li>Monthly AI reflection (1 per month)</li>
                    <li>Receipt photo scanning (upload)</li>
                    <li>Gentle categorization assistance</li>
                </ul>
                <p class="pricing-note">Weekly and monthly insights stay calm and optional.</p>
                <router-link class="primary-button wide" :to="{ name: 'register' }">Upgrade to Pro</router-link>
            </article>

            <article class="card pricing-card">
                <header>
                    <p class="eyebrow">Premium</p>
                    <h2 class="section-title">$25 <span class="price-unit">/ month</span></h2>
                    <p class="section-copy">For those who want Penny fully by their side.</p>
                </header>
                <ul class="pricing-list">
                    <li>Everything in Pro</li>
                    <li>Unlimited AI insights</li>
                    <li>Receipt photo scanning</li>
                    <li>Bank statement uploads</li>
                    <li>Automatic income & spending detection</li>
                    <li>Deeper trend analysis</li>
                    <li>Priority future features</li>
                </ul>
                <p class="pricing-note">Full support, still at your pace.</p>
                <router-link class="primary-button wide" :to="{ name: 'register' }">Go Premium</router-link>
            </article>
        </section>

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
                    <span>Manual tracking</span>
                    <span>Included</span>
                    <span>Included</span>
                    <span>Included</span>
                </div>
                <div class="compare-row">
                    <span>AI insights</span>
                    <span>—</span>
                    <span>Weekly + monthly</span>
                    <span>Unlimited</span>
                </div>
                <div class="compare-row">
                    <span>Receipt scanning</span>
                    <span>—</span>
                    <span>Yes</span>
                    <span>Yes</span>
                </div>
                <div class="compare-row">
                    <span>Bank statements</span>
                    <span>—</span>
                    <span>—</span>
                    <span>Yes</span>
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
                    <summary>What happens if I downgrade?</summary>
                    <p>Your data stays intact. Some features pause, but nothing is lost.</p>
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
