<template>
    <section class="marketing">
        <header class="hero">
            <div class="hero-copy">
                <p class="eyebrow">Penny</p>
                <h1 class="hero-title">Penny — your calm money companion</h1>
                <p class="hero-sub">
                    A gentle way to track spending, understand your habits, and save without stress.
                </p>
                <div class="hero-actions">
                    <router-link class="primary-button" :to="{ name: 'login' }">Get Penny</router-link>
                    <a class="ghost-button" href="#how">How it works</a>
                </div>
                <p class="hero-note">Mobile-first. Desktop is supported for viewing only.</p>
            </div>
            <div class="hero-card card">
                <div class="card-title">Made for small moments</div>
                <p class="card-sub">
                    Penny is designed to live with you — on your phone, in your pocket, at your pace.
                </p>
                <div class="hero-points">
                    <div class="hero-point">No pressure, no alerts, no shame.</div>
                    <div class="hero-point">Install once. Check in whenever you want.</div>
                    <div class="hero-point">Everything stays simple and calm.</div>
                </div>
            </div>
        </header>

        <section class="card marketing-section" id="what">
            <h2 class="section-title">What Penny is (and is not)</h2>
            <p class="section-copy">
                Penny is designed for your phone and works best when installed as a PWA. It is not a
                traditional desktop finance dashboard. This is intentional. Penny is meant to support
                small moments, not big screens.
            </p>
        </section>

        <section class="marketing-grid">
            <div class="card marketing-section" id="mobile">
                <h2 class="section-title">Mobile-first, on purpose</h2>
                <p class="section-copy">
                    The full experience lives on your phone. Desktop is supported for viewing only.
                </p>
                <p class="section-copy">
                    Installing Penny gives the most calm, focused experience — no distractions.
                </p>
            </div>
            <div class="card marketing-section" id="how">
                <h2 class="section-title">How Penny works</h2>
                <ul class="step-list">
                    <li>Add spending moments on your phone.</li>
                    <li>Penny organizes things quietly.</li>
                    <li>You check in when you’re ready.</li>
                    <li>No charts, no numbers.</li>
                </ul>
            </div>
        </section>

        <section class="card marketing-section" id="offline">
            <h2 class="section-title">Offline-first and light</h2>
            <p class="section-copy">
                Penny works even without service. You can add spending, scan receipts, and review
                history. Everything syncs automatically later.
            </p>
            <div class="pill-row">
                <span class="pill">Installs like an app</span>
                <span class="pill">No app store needed</span>
                <span class="pill">Fast and lightweight</span>
            </div>
        </section>

        <section class="card marketing-section" id="install">
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
            <p class="section-copy">
                Installing Penny unlocks the full experience.
            </p>
        </section>

        <section class="marketing-grid">
            <div class="card marketing-section" id="different">
                <h2 class="section-title">Why Penny feels different</h2>
                <ul class="step-list">
                    <li>No pressure. No shame.</li>
                    <li>No constant alerts.</li>
                    <li>No forced bank connections.</li>
                    <li>You choose what to add.</li>
                </ul>
            </div>
            <div class="card marketing-section" id="privacy">
                <h2 class="section-title">Privacy & trust</h2>
                <p class="section-copy">
                    Your data belongs to you. No ads. No selling your information. Offline-first by
                    design. Bank statements are optional and always manual.
                </p>
            </div>
        </section>

        <section class="card marketing-section" id="faq">
            <h2 class="section-title">FAQ</h2>
            <div class="faq-list">
                <details>
                    <summary>Is Penny free?</summary>
                    <p>Yes. Penny is free to use.</p>
                </details>
                <details>
                    <summary>Do I need to connect a bank?</summary>
                    <p>No. Bank connections are never required.</p>
                </details>
                <details>
                    <summary>Does it work offline?</summary>
                    <p>Yes. Penny keeps working and syncs later.</p>
                </details>
                <details>
                    <summary>Is this a mobile app?</summary>
                    <p>It is a PWA designed for your phone.</p>
                </details>
                <details>
                    <summary>Can I use it on desktop?</summary>
                    <p>Desktop is supported for viewing only.</p>
                </details>
                <details>
                    <summary>How do I install it?</summary>
                    <p>Use “Add to Home Screen” on iPhone or “Install App” on Android.</p>
                </details>
            </div>
        </section>

        <section class="final-cta card">
            <h2 class="section-title">Start with one small step.</h2>
            <router-link class="primary-button wide" :to="{ name: 'login' }">Get Penny</router-link>
            <p class="section-copy">No pressure. Just clarity.</p>
        </section>
    </section>
</template>
