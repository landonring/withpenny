<nav class="marketing-nav w-full bg-canvas/80 backdrop-blur-md sticky top-0 z-50 border-b border-transparent transition-all duration-300">
    <div class="max-w-7xl mx-auto px-6 h-24 flex items-center justify-between">
        <a class="flex items-center gap-2 group cursor-pointer" href="/">
            <span class="text-2xl font-serif font-medium text-text-heading tracking-tight">Penny</span>
        </a>
        <button class="marketing-mobile-toggle" type="button" aria-expanded="false" data-mobile-menu-open>
            <span class="marketing-mobile-icon" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </span>
            <span class="marketing-mobile-label">Menu</span>
        </button>
        <div class="marketing-nav-links text-sm font-medium text-text-body">
            <a class="hover:text-text-heading transition-colors" href="/#how">How it works</a>
            <a class="hover:text-text-heading transition-colors" href="/#install">How to install</a>
            <a class="hover:text-text-heading transition-colors" href="/#pricing">Pricing</a>
            <a class="hover:text-text-heading transition-colors" href="/#faq">FAQ</a>
            <a class="hover:text-text-heading transition-colors" href="/budgeting-app-guide">Guide</a>
            <a class="hover:text-text-heading transition-colors" href="/blog">Blog</a>
        </div>
<link href="/marketing.css?v={{ filemtime(public_path('marketing.css')) }}" rel="stylesheet"/>
<link href="/marketing-overrides.css?v={{ filemtime(public_path('marketing-overrides.css')) }}" rel="stylesheet"/>
        @php
            $isLoggedIn = auth()->check();
        @endphp
        <button id="login-cta" data-logged-in="{{ $isLoggedIn ? 'true' : 'false' }}" class="marketing-login-cta px-6 py-2.5 rounded-full text-sm font-medium text-text-heading bg-transparent border border-border-soft hover:bg-white hover:border-accent-sage transition-all duration-300">
            {{ $isLoggedIn ? 'Dashboard' : 'Login' }}
        </button>
    </div>
</nav>
<div class="marketing-mobile-menu" data-mobile-menu aria-hidden="true">
    <div class="marketing-mobile-menu-header">
        <a class="flex items-center gap-2" href="/">
            <span class="text-2xl font-serif font-medium text-text-heading tracking-tight">Penny</span>
        </a>
        <button class="marketing-mobile-close" type="button" aria-label="Close menu" data-mobile-menu-close>
            <span>×</span>
        </button>
    </div>
    <div class="marketing-mobile-menu-content">
        <a href="/#how">About</a>
        <a href="/#install">Installation</a>
        <a href="/#pricing">Pricing</a>
        <a href="/#faq">FAQ</a>
        <a href="/budgeting-app-guide">Guide</a>
        <a href="/blog">Blog</a>
        <a id="login-cta-mobile" href="/login">Login</a>
    </div>
</div>

<button
    class="roadmap-fab"
    type="button"
    data-roadmap-open
    aria-haspopup="dialog"
    aria-expanded="false"
    aria-controls="public-roadmap-panel"
>
    Penny’s Roadmap
</button>

<div class="roadmap-overlay" data-roadmap-overlay aria-hidden="true" hidden>
    <aside
        class="roadmap-panel"
        id="public-roadmap-panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="roadmap-panel-title"
        tabindex="-1"
    >
        <header class="roadmap-panel-header">
            <div class="roadmap-brand">
                <span class="roadmap-brand-mark" aria-hidden="true">P</span>
                <div class="roadmap-brand-copy">
                    <h2 id="roadmap-panel-title">Penny Roadmap</h2>
                    <p>Suggest features, explore our roadmap, and follow what’s shipping.</p>
                </div>
            </div>
            <button class="roadmap-close" type="button" data-roadmap-close aria-label="Close roadmap panel">×</button>
        </header>

        <nav class="roadmap-tabs" role="tablist" aria-label="Roadmap tabs">
            <button class="roadmap-tab is-active" type="button" role="tab" data-roadmap-tab="ideas" aria-selected="true">
                Ideas
            </button>
            <button class="roadmap-tab" type="button" role="tab" data-roadmap-tab="bugs" aria-selected="false">
                Bugs
            </button>
            <button class="roadmap-tab" type="button" role="tab" data-roadmap-tab="roadmap" aria-selected="false">
                Roadmap
            </button>
            <button class="roadmap-tab" type="button" role="tab" data-roadmap-tab="announcements" aria-selected="false">
                Announcements
            </button>
        </nav>

        <div class="roadmap-content">
            <p class="roadmap-inline-error" data-roadmap-inline-error hidden></p>

            <section class="roadmap-view is-active" data-roadmap-view="ideas" aria-live="polite">
                <div class="roadmap-toolbar">
                    <button class="roadmap-primary" type="button" data-roadmap-open-form>Suggest a Feature</button>
                </div>
                <p class="roadmap-empty" data-roadmap-ideas-empty hidden>Nothing here yet.</p>
                <div class="roadmap-idea-list" data-roadmap-ideas-list></div>
            </section>

            <section class="roadmap-view" data-roadmap-view="bugs" aria-live="polite">
                <div class="roadmap-toolbar">
                    <button class="roadmap-primary roadmap-secondary" type="button" data-roadmap-open-bug-form>Report a Bug</button>
                </div>
                <p class="roadmap-empty" data-roadmap-bugs-empty hidden>Nothing here yet.</p>
                <div class="roadmap-idea-list" data-roadmap-bugs-list></div>
            </section>

            <section class="roadmap-view" data-roadmap-view="roadmap" aria-live="polite">
                <p class="roadmap-empty" data-roadmap-roadmap-empty hidden>Nothing here yet.</p>
                <div class="roadmap-roadmap-list" data-roadmap-roadmap-list></div>
            </section>

            <section class="roadmap-view" data-roadmap-view="announcements" aria-live="polite">
                <p class="roadmap-empty" data-roadmap-announcements-empty hidden>Nothing here yet.</p>
                <div class="roadmap-announcements-list" data-roadmap-announcements-list></div>
            </section>

            <section class="roadmap-view" data-roadmap-view="detail">
                <button class="roadmap-back" type="button" data-roadmap-back>← Back to Ideas</button>
                <article class="roadmap-detail" data-roadmap-detail></article>
            </section>

            <section class="roadmap-view" data-roadmap-view="form">
                <button class="roadmap-back" type="button" data-roadmap-form-cancel>← Back to Ideas</button>
                <form class="roadmap-form" data-roadmap-form>
                    <h3 data-roadmap-form-title>Suggest a Feature</h3>
                    <label>
                        <span data-roadmap-form-summary-label>One-line summary</span>
                        <input type="text" maxlength="160" required data-roadmap-form-summary />
                    </label>
                    <label>
                        <span data-roadmap-form-description-label>Description</span>
                        <textarea rows="6" maxlength="6000" required data-roadmap-form-description></textarea>
                    </label>
                    <label data-roadmap-form-browser-wrap hidden>
                        <span>Device or browser notes (optional)</span>
                        <textarea rows="3" maxlength="800" data-roadmap-form-browser-notes></textarea>
                    </label>
                    <label data-roadmap-form-screenshot-wrap hidden>
                        <span>Screenshot (optional)</span>
                        <input type="file" accept="image/*" data-roadmap-form-screenshot />
                    </label>
                    <div>
                        <span class="roadmap-label">Topic</span>
                        <div class="roadmap-topic-chips" role="group" aria-label="Suggestion topic">
                            <button type="button" class="roadmap-chip is-active" data-roadmap-topic="planning">Planning</button>
                            <button type="button" class="roadmap-chip" data-roadmap-topic="automation">Automation</button>
                            <button type="button" class="roadmap-chip" data-roadmap-topic="insights">Insights</button>
                            <button type="button" class="roadmap-chip" data-roadmap-topic="mobile">Mobile</button>
                            <button type="button" class="roadmap-chip" data-roadmap-topic="bug">Bug</button>
                        </div>
                    </div>
                    <p class="roadmap-form-error" data-roadmap-form-error hidden></p>
                    <button class="roadmap-primary" type="submit" data-roadmap-form-submit>Submit</button>
                </form>
                <div class="roadmap-form-success" data-roadmap-form-success hidden>
                    <p data-roadmap-form-success-text>Your idea has been submitted.</p>
                </div>
            </section>
        </div>
    </aside>

    <div class="roadmap-signin-modal" data-roadmap-signin-modal hidden>
        <div class="roadmap-signin-card" role="dialog" aria-modal="true" aria-labelledby="roadmap-signin-title">
            <h3 id="roadmap-signin-title" data-roadmap-signin-title>Sign in to continue</h3>
            <p data-roadmap-signin-copy>Sign in to continue.</p>
            <div class="roadmap-signin-actions">
                <button class="roadmap-primary" type="button" data-roadmap-signin-login>Sign in</button>
                <button class="roadmap-primary roadmap-secondary" type="button" data-roadmap-signin-register>Create account</button>
                <button class="roadmap-text-button" type="button" data-roadmap-signin-cancel>Not now</button>
            </div>
        </div>
    </div>
</div>
