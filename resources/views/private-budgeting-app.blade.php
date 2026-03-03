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
    @php
        $faqSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => [
                [
                    '@type' => 'Question',
                    'name' => 'What is a private budgeting app?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'A private budgeting app lets users track spending while keeping control over what data is shared and when.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'Does Penny require bank linking?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'No. Penny works without mandatory bank account integration. Manual tracking and statement uploads are optional.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'Can a private budgeting app still provide AI insights?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Yes. Penny provides optional AI insights using the financial data users choose to track in-app.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'Who is Penny best for?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Penny supports individuals, couples, families, and privacy-conscious users seeking calm budgeting workflows.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'Is private budgeting harder than automated budgeting?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Private budgeting can be simple when workflows are structured. Penny focuses on low-friction routines and practical summaries.',
                    ],
                ],
            ],
        ];
    @endphp
    <script type="application/ld+json">{!! json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>
</head>
<body class="bg-canvas text-text-body font-sans antialiased selection:bg-accent-sage selection:text-text-heading article-shell">
@include('partials.marketing-nav')

<header class="article-header px-6">
    <div class="max-w-4xl mx-auto text-center">
        <p class="article-eyebrow">SEO Landing Page</p>
        <h1 class="article-title text-4xl md:text-5xl">Private Budgeting</h1>
        <p class="text-lg text-text-body mt-6 max-w-2xl mx-auto">Penny is a private budgeting app designed for people who want structured money awareness without mandatory bank account integration.</p>
    </div>
</header>

<section class="px-6 pb-24">
    <div class="max-w-6xl mx-auto space-y-8">
        <section class="bg-card border border-border-soft rounded-xl p-8 md:p-10">
            <p class="text-text-body leading-relaxed">A private budgeting app should give people control, not just convenience. Penny is built around intentional tracking, optional uploads, and clear review steps so users can keep financial awareness without sharing more than they choose. This makes budgeting more sustainable for people who value privacy and calm workflows.</p>
            <p class="text-text-body leading-relaxed mt-4">Penny supports manual entries, bank statement upload review, and AI-powered reflections while preserving user choice at every step. That model creates a practical alternative to always-on account aggregation.</p>
        </section>

        <section class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <article class="bg-card border border-border-soft rounded-xl p-8">
                <h2 class="text-3xl font-serif text-text-heading mb-4">Budgeting Without Forced Connections</h2>
                <p class="text-text-body leading-relaxed">Private budgeting starts with consent. Penny does not require direct bank linking to begin. Users can add transactions manually or upload statements when useful, then review every entry before import. This keeps the process transparent and easier to trust.</p>
                <p class="text-text-body leading-relaxed mt-3">For many people, that boundary reduces money stress. Instead of continuous background syncing, they choose focused review windows and track what matters most.</p>
            </article>
            <article class="bg-card border border-border-soft rounded-xl p-8">
                <h2 class="text-3xl font-serif text-text-heading mb-4">Control Over Financial Data</h2>
                <p class="text-text-body leading-relaxed">A private budgeting app must make data boundaries explicit. Penny keeps workflows user-led: what is uploaded, what is reviewed, and what is confirmed. This structure supports better data ownership and fewer surprises.</p>
                <p class="text-text-body leading-relaxed mt-3">Optional AI support then uses available data to provide practical summaries. Users get financial context without giving up control of the budgeting process.</p>
            </article>
        </section>

        <section class="bg-card border border-border-soft rounded-xl p-8 md:p-10">
            <h2 class="text-3xl font-serif text-text-heading mb-5">Why Private Budgeting Works</h2>
            <ul class="space-y-4 text-text-body leading-relaxed">
                <li><strong class="text-text-heading">Manual control:</strong> Add, edit, and confirm transactions intentionally.</li>
                <li><strong class="text-text-heading">Statement upload review:</strong> Inspect entries before anything is saved.</li>
                <li><strong class="text-text-heading">Secure workflows:</strong> Keep budgeting focused without continuous external account syncing.</li>
                <li><strong class="text-text-heading">Optional AI assistance:</strong> Generate insights only when needed.</li>
                <li><strong class="text-text-heading">Calm interface:</strong> Minimal presentation that supports routine check-ins.</li>
            </ul>
        </section>

        <section class="bg-card border border-border-soft rounded-xl p-8 md:p-10">
            <h2 class="text-3xl font-serif text-text-heading mb-5">Who Chooses a Private Budgeting App</h2>
            <p class="text-text-body leading-relaxed">Private budgeting is a strong fit for users who prefer low-noise finance tools and clear data ownership. Penny is used by individuals managing personal spending, couples aligning monthly priorities, and families tracking household costs without exposing full account histories.</p>
            <p class="text-text-body leading-relaxed mt-4">Freelancers and variable-income earners also benefit because they can focus on practical cash-flow review without relying on rigid automation. The result is less dashboard fatigue and more consistent financial awareness.</p>
        </section>

        <section class="bg-card border border-border-soft rounded-xl p-8 md:p-10" id="faq-private-budgeting-app">
            <h2 class="text-3xl font-serif text-text-heading mb-6">Frequently Asked Questions</h2>
            <div class="marketing-faq-list">
                <details class="marketing-faq-item" open>
                    <summary class="marketing-faq-question">Is Penny a private budgeting app?<span class="marketing-faq-icon" aria-hidden="true">+</span></summary>
                    <p class="marketing-faq-answer">Yes. Penny is a private budgeting app with manual control and optional statement workflows.</p>
                </details>
                <details class="marketing-faq-item">
                    <summary class="marketing-faq-question">Does Penny require bank account integration?<span class="marketing-faq-icon" aria-hidden="true">+</span></summary>
                    <p class="marketing-faq-answer">No. Users can budget without direct account linking and still get useful AI-supported summaries.</p>
                </details>
                <details class="marketing-faq-item">
                    <summary class="marketing-faq-question">Can Penny still provide useful insights without full account syncing?<span class="marketing-faq-icon" aria-hidden="true">+</span></summary>
                    <p class="marketing-faq-answer">Yes. Insight generation uses tracked data and confirmed transactions to highlight practical trends.</p>
                </details>
                <details class="marketing-faq-item">
                    <summary class="marketing-faq-question">Who is private budgeting best for?<span class="marketing-faq-icon" aria-hidden="true">+</span></summary>
                    <p class="marketing-faq-answer">It is ideal for privacy-conscious users, families, and anyone who wants deliberate financial tracking with less noise.</p>
                </details>
                <details class="marketing-faq-item">
                    <summary class="marketing-faq-question">How does Penny compare with traditional connected budgeting apps?<span class="marketing-faq-icon" aria-hidden="true">+</span></summary>
                    <p class="marketing-faq-answer">Penny prioritizes calm structure, manual review control, and optional AI assistance instead of always-on aggregation.</p>
                </details>
            </div>
        </section>

        <section class="bg-card border border-border-soft rounded-xl p-8 md:p-10 text-center">
            <h2 class="text-3xl font-serif text-text-heading mb-3">Start Private Budgeting With Penny</h2>
            <p class="text-text-body max-w-2xl mx-auto leading-relaxed">If privacy and practical structure matter, Penny gives you a calm budgeting workflow with optional AI insight generation.</p>
            <div class="mt-8 flex flex-col sm:flex-row gap-4 justify-center">
                <a class="px-6 py-3 rounded-full bg-accent-sage/60 text-text-heading text-sm font-medium" href="/register">Try Penny Free</a>
                <a class="px-6 py-3 rounded-full border border-border-soft text-text-heading text-sm font-medium" href="/best-ai-budgeting-app">Explore AI budgeting</a>
            </div>
        </section>
    </div>
</section>

@include('partials.marketing-footer')
@include('partials.marketing-scripts')
</body>
</html>
