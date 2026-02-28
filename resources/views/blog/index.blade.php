<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Penny Blog - Calm Budgeting, Money Habits, and AI Tools</title>
    <meta name="description" content="Read Penny's budgeting blog for calm, practical guidance on money habits, AI budgeting tools, privacy-first tracking, and minimalist budgeting strategies."/>
    <meta name="csrf-token" content="{{ csrf_token() }}"/>
    <link rel="canonical" href="{{ url('/blog') }}"/>
    <meta property="og:title" content="Penny Blog - Calm Budgeting, Money Habits, and AI Tools"/>
    <meta property="og:description" content="Calm, practical guidance on money habits, AI budgeting tools, and privacy-first budgeting."/>
    <meta property="og:site_name" content="Penny"/>
    <meta property="og:image:alt" content="Penny logo"/>
    <meta property="og:type" content="website"/>
    <meta property="og:url" content="{{ url('/blog') }}"/>
    <meta property="og:image" content="{{ url('/og/penny-share.png?v=' . filemtime(public_path('og/penny-share.png'))) }}"/>
    <meta property="og:image:type" content="image/png"/>
    <meta property="og:image:width" content="1200"/>
    <meta property="og:image:height" content="630"/>
    <meta name="twitter:card" content="summary_large_image"/>
    <meta name="twitter:title" content="Penny Blog - Calm Budgeting, Money Habits, and AI Tools"/>
    <meta name="twitter:description" content="Calm, practical guidance on money habits, AI budgeting tools, and privacy-first budgeting."/>
    <meta name="twitter:image" content="{{ url('/og/penny-share.png?v=' . filemtime(public_path('og/penny-share.png'))) }}"/>
    <meta name="twitter:image:alt" content="Penny logo"/>
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
<body class="bg-canvas text-text-body font-sans antialiased selection:bg-accent-sage selection:text-text-heading article-shell">
@include('partials.marketing-nav')

<header class="article-header px-6">
    <div class="max-w-4xl mx-auto text-center">
        <p class="article-eyebrow">Budgeting Blog</p>
        <h1 class="article-title text-4xl md:text-5xl">Calm budgeting, money habits, and AI tools</h1>
        <p class="text-lg text-text-body mt-6">Short, practical reads designed to help you build a calmer money system - without pressure or jargon.</p>
        <div class="mt-8">
            <a class="px-6 py-3 rounded-full bg-accent-sage/60 text-text-heading text-sm font-medium inline-flex" href="/budgeting-app-guide">Start with the Budgeting App Guide</a>
        </div>
    </div>
</header>

<section class="px-6 pb-32">
    <div class="max-w-6xl mx-auto">
        <div class="article-grid">
            <a class="article-card" href="/blog/privacy-budgeting-app">
                <p class="article-eyebrow">Privacy</p>
                <h2 class="article-card-title">Privacy budgeting app: a manual budgeting app without bank linking</h2>
                <p class="article-card-meta">What makes Penny different, without the noise.</p>
            </a>
            <a class="article-card" href="/blog/manual-budgeting-benefits">
                <p class="article-eyebrow">Mindful</p>
                <h2 class="article-card-title">Manual budgeting benefits and mindful budgeting</h2>
                <p class="article-card-meta">Why manual budgeting is making a comeback.</p>
            </a>
            <a class="article-card" href="/blog/ai-budgeting-tools">
                <p class="article-eyebrow">AI</p>
                <h2 class="article-card-title">AI budgeting tools and personal finance AI</h2>
                <p class="article-card-meta">Gentle reflections without taking over your life.</p>
            </a>
            <a class="article-card" href="/blog/budgeting-for-anxiety">
                <p class="article-eyebrow">Calm</p>
                <h2 class="article-card-title">Budgeting for anxiety</h2>
                <p class="article-card-meta">A calm budgeting app approach without overwhelm.</p>
            </a>
            <a class="article-card" href="/blog/budgeting-without-bank-account">
                <p class="article-eyebrow">Privacy</p>
                <h2 class="article-card-title">Budgeting without bank account access</h2>
                <p class="article-card-meta">Manual tracking with a privacy-first mindset.</p>
            </a>
            <a class="article-card" href="/blog/how-to-start-a-budget">
                <p class="article-eyebrow">Basics</p>
                <h2 class="article-card-title">How to start a budget</h2>
                <p class="article-card-meta">A beginner guide with a simple method.</p>
            </a>
            <a class="article-card" href="/blog/receipt-scanning-budgeting-app">
                <p class="article-eyebrow">Receipts</p>
                <h2 class="article-card-title">Receipt scanning app and OCR budgeting app</h2>
                <p class="article-card-meta">How uploads work and what to review.</p>
            </a>
            <a class="article-card" href="/blog/pwa-budgeting-apps">
                <p class="article-eyebrow">PWA</p>
                <h2 class="article-card-title">PWA budgeting apps</h2>
                <p class="article-card-meta">Install without the app store, keep it light.</p>
            </a>
            <a class="article-card" href="/blog/50-30-20-budget-method">
                <p class="article-eyebrow">Methods</p>
                <h2 class="article-card-title">50 30 20 budget method</h2>
                <p class="article-card-meta">A simple formula with gentle tweaks.</p>
            </a>
            <a class="article-card" href="/blog/weekly-money-reflection">
                <p class="article-eyebrow">Reflections</p>
                <h2 class="article-card-title">Weekly budget review and monthly budget reflection</h2>
                <p class="article-card-meta">Mindful money habits that last.</p>
            </a>
        </div>
    </div>
</section>

@include('partials.marketing-footer')
@include('partials.marketing-scripts')
</body>
</html>
