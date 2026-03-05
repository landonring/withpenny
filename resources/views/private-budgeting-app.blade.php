<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Private Budgeting App | Penny</title>
    <meta name="description" content="A private budgeting app that doesn’t require bank linking. Stay in control with Penny."/>
    <meta name="csrf-token" content="{{ csrf_token() }}"/>
    <link rel="canonical" href="{{ url('/private-budgeting-app') }}"/>
    <meta property="og:title" content="Private Budgeting App | Penny"/>
    <meta property="og:description" content="Penny is a private budgeting app with manual control, optional uploads, and calm AI support."/>
    <meta property="og:type" content="website"/>
    <meta property="og:url" content="{{ url('/private-budgeting-app') }}"/>
    <meta property="og:image" content="{{ url('/og/penny-share.png?v=' . filemtime(public_path('og/penny-share.png'))) }}"/>
    <meta name="twitter:card" content="summary_large_image"/>
    <meta name="twitter:title" content="Private Budgeting App | Penny"/>
    <meta name="twitter:description" content="Budget with privacy and control. Penny works without forced bank linking and supports calm financial awareness."/>
    <meta name="twitter:image" content="{{ url('/og/penny-share.png?v=' . filemtime(public_path('og/penny-share.png'))) }}"/>
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/penny-192.png"/>
    <link rel="icon" type="image/png" sizes="512x512" href="/icons/penny-512.png"/>
    <link rel="apple-touch-icon" sizes="180x180" href="/icons/penny-192.png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Playfair+Display:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet"/>
    <link href="/marketing.css?v={{ filemtime(public_path('marketing.css')) }}" rel="stylesheet"/>
    <link href="/marketing-articles.css?v={{ filemtime(public_path('marketing-articles.css')) }}" rel="stylesheet"/>
    <link href="/marketing-overrides.css?v={{ filemtime(public_path('marketing-overrides.css')) }}" rel="stylesheet"/>
</head>
<body class="bg-canvas text-text-body font-sans antialiased selection:bg-accent-sage selection:text-text-heading article-shell seo-page seo-private-narrative">
@include('partials.marketing-nav')

<header class="seo-hero">
    <div class="seo-shell seo-hero-inner">
        <p class="seo-kicker">Privacy-First Budgeting</p>
        <h1 class="seo-hero-title">Private Budgeting App With Full Control</h1>
        <p class="seo-hero-subtitle">A calmer budgeting model for people who want financial clarity without mandatory bank linking or continuous data sharing.</p>
        <div class="seo-hero-actions">
            <a class="seo-btn seo-btn-primary" href="/register">Try Penny Free</a>
            <a class="seo-btn seo-btn-secondary" href="/budgeting-app-guide">Read the guide</a>
        </div>
    </div>
</header>

<main class="seo-main">
    <section class="seo-section seo-problem">
        <div class="seo-shell seo-narrow-copy">
            <h2>The Privacy Problem</h2>
            <p>Most budgeting products default to account aggregation. It is fast, but it can feel invasive. Users often trade visibility for convenience before they fully understand what is shared and how often it is synced.</p>
            <p>Private budgeting starts from a different principle: money awareness should not require permanent external access. The goal is clarity with boundaries, not just automation at any cost.</p>
            <blockquote class="seo-pull-quote">Private budgeting is not anti-technology. It is pro-consent, pro-review, and pro-user control.</blockquote>
        </div>
    </section>

    <section class="seo-section seo-meaning">
        <div class="seo-shell seo-two-col-copy">
            <div>
                <h2>What “Private Budgeting” Actually Means</h2>
            </div>
            <div>
                <p>Private budgeting means you decide what enters your system, when it enters, and when it becomes permanent. It favors intentional workflows over invisible background processing.</p>
                <ul class="seo-principles">
                    <li><strong>Consent-first data flow</strong>: no forced bank connection as a prerequisite.</li>
                    <li><strong>Review before commit</strong>: imported transactions stay editable until confirmed.</li>
                    <li><strong>Clear deletion path</strong>: temporary files and parsing artifacts should not linger.</li>
                    <li><strong>AI as assistant, not authority</strong>: suggestions support judgment; they do not replace it.</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="seo-section seo-difference">
        <div class="seo-shell seo-difference-split">
            <div class="seo-sticky-title">
                <h2>How Penny Is Different</h2>
            </div>
            <div class="seo-difference-list">
                <article>
                    <h3>Deliberate imports</h3>
                    <p>Statement and receipt workflows surface extracted rows for review instead of silently writing them to your ledger.</p>
                </article>
                <article>
                    <h3>Confidence-aware parsing</h3>
                    <p>Penny flags uncertain data and routes low-confidence cases to manual review rather than pretending extraction is complete.</p>
                </article>
                <article>
                    <h3>Category suggestions with context</h3>
                    <p>AI suggests categories from transaction descriptions but leaves low-confidence items uncategorized by default.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="seo-section seo-compare-mid">
        <div class="seo-shell">
            <h2>Private Budgeting vs Connected Budgeting</h2>
            <div class="seo-table-wrap">
                <table class="seo-table">
                    <thead>
                    <tr>
                        <th>Dimension</th>
                        <th>Penny (Private-First)</th>
                        <th>Connected Aggregators</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td>Default data model</td>
                        <td>User-controlled entry and uploads</td>
                        <td>Continuous bank-linked synchronization</td>
                    </tr>
                    <tr>
                        <td>Error handling</td>
                        <td>Explicit review and correction flow</td>
                        <td>Often hidden behind automated categorization</td>
                    </tr>
                    <tr>
                        <td>Trust model</td>
                        <td>Confirm before save</td>
                        <td>Sync first, audit later</td>
                    </tr>
                    <tr>
                        <td>Best fit</td>
                        <td>Privacy-conscious users and households</td>
                        <td>Automation-first users with low review tolerance</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="seo-section seo-audience">
        <div class="seo-shell">
            <h2>Who This Is For</h2>
            <div class="seo-audience-grid">
                <article>
                    <h3>Privacy-conscious professionals</h3>
                    <p>People who want budget visibility without always-on account access.</p>
                </article>
                <article>
                    <h3>Families and couples</h3>
                    <p>Households that need shared review and explicit confirmation before records are final.</p>
                </article>
                <article>
                    <h3>Manual-first budgeters</h3>
                    <p>Users who trust intentional routines more than automated feeds.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="seo-final-cta">
        <div class="seo-shell seo-final-cta-inner">
            <h2>Budget with privacy, not guesswork.</h2>
            <p>Build a deliberate money rhythm with review-first imports, clear categorization, and optional AI support.</p>
            <a class="seo-btn seo-btn-light" href="/register">Start with Penny</a>
        </div>
    </section>
</main>

@include('partials.marketing-footer', ['showPrefooterCta' => false])
@include('partials.marketing-scripts')
</body>
</html>
