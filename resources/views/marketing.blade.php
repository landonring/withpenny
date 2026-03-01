<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Penny – AI Budgeting Assistant for Individuals, Couples & Families</title>
<meta name="description" content="Penny is an AI-powered budgeting assistant that helps individuals, couples, and families gain clarity around spending. Scan statements, generate insights, and build calm financial awareness."/>
<meta name="keywords" content="AI budgeting app, personal budgeting assistant, spending tracker, budgeting without bank connection, AI finance assistant"/>
<meta name="csrf-token" content="{{ csrf_token() }}"/>
<link rel="canonical" href="https://withpenny.app"/>
<meta property="og:title" content="Penny – AI Budgeting Assistant for Individuals, Couples & Families"/>
<meta property="og:description" content="Penny is an AI-powered budgeting assistant that helps individuals, couples, and families gain clarity around spending."/>
<meta property="og:type" content="website"/>
<meta property="og:url" content="https://withpenny.app"/>
<meta property="og:image" content="{{ url('/icons/penny-512.png') }}"/>
<meta name="twitter:card" content="summary_large_image"/>
<meta name="twitter:title" content="Penny – AI Budgeting Assistant for Individuals, Couples & Families"/>
<meta name="twitter:description" content="Penny is an AI-powered budgeting assistant for calm money clarity, statement scanning, and practical spending insights."/>
<meta name="twitter:image" content="{{ url('/icons/penny-512.png') }}"/>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
<link rel="icon" type="image/png" sizes="192x192" href="/icons/penny-192.png"/>
<link rel="icon" type="image/png" sizes="512x512" href="/icons/penny-512.png"/>
<link rel="apple-touch-icon" sizes="180x180" href="/icons/penny-192.png"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&amp;family=Playfair+Display:ital,wght@0,400;0,500;0,600;1,400&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<link href="/marketing.css?v={{ filemtime(public_path('marketing.css')) }}" rel="stylesheet"/>
<link href="/marketing-overrides.css?v={{ filemtime(public_path('marketing-overrides.css')) }}" rel="stylesheet"/>
<style>
    .sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }
</style>
@php
    $softwareSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => 'Penny',
        'applicationCategory' => 'FinanceApplication',
        'operatingSystem' => 'Web',
        'url' => 'https://withpenny.app',
        'image' => url('/icons/penny-512.png'),
        'description' => 'AI-powered budgeting assistant for individuals, couples, and families.',
        'offers' => [
            '@type' => 'AggregateOffer',
            'lowPrice' => 0,
            'highPrice' => 25,
            'priceCurrency' => 'USD',
        ],
        'featureList' => [
            'Manual transaction entry',
            'Receipt scanning',
            'Privacy-first budgeting',
            'Offline support',
            'AI reflections (optional)',
        ],
    ];
    $faqSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => [
            [
                '@type' => 'Question',
                'name' => 'Is Penny an AI budgeting app?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Yes. Penny is an AI-powered budgeting assistant that combines manual tracking, statement scanning, and calm insights.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'Does Penny require bank integration?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'No. Penny works without direct bank account connection. You can track manually or upload statements when you choose.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'Is Penny secure?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Yes. Penny is privacy-first and built around user control. Your budgeting workflow is designed to minimize unnecessary data exposure.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'Can Penny help individuals budget?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Yes. Penny helps individuals build practical budgeting habits with manual tracking, spending insights, and AI support.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'Is Penny good for couples?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Yes. Penny works well for couples who want shared awareness, calmer money check-ins, and better spending alignment.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'How is Penny different from Mint or YNAB?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Penny emphasizes calm guidance, privacy-first workflows, and optional AI support. It is designed for clarity without complexity.',
                ],
            ],
        ],
    ];
@endphp
<script type="application/ld+json">{!! json_encode($softwareSchema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>
<script type="application/ld+json">{!! json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>
</head>
<body class="bg-canvas text-text-body font-sans antialiased selection:bg-accent-sage selection:text-text-heading">
@include('partials.marketing-nav')
<h1 class="sr-only">AI Budgeting Assistant for Individuals, Couples, and Families</h1>
<header class="relative w-full pt-20 pb-16 px-6" id="top">
<div class="max-w-4xl mx-auto text-center relative z-10">
<div class="inline-flex items-center gap-2 mb-8 px-4 py-1.5 rounded-full bg-white/50 border border-border-soft">
<span class="w-1.5 h-1.5 rounded-full bg-accent-label"></span>
<span class="text-[10px] uppercase tracking-widest font-semibold text-accent-label">Finance Simplified</span>
</div>
<div class="text-6xl lg:text-8xl font-serif font-medium leading-[1.1] text-text-heading mb-10 text-balance">
            Penny — your <br/>
<span class="italic text-primary-sage">calm money</span> companion.
        </div>
<p class="text-xl text-text-body max-w-xl mx-auto leading-relaxed mb-12 text-balance">
            Master your finances without the noise. A strict, minimalist approach to personal wealth management designed for peace of mind.
        </p>
<p class="text-sm text-text-body/70 max-w-xl mx-auto leading-relaxed mb-8 text-balance">
            Mobile-first experience, fully compatible on desktop.
        </p>
<div class="flex flex-col sm:flex-row items-center justify-center gap-6">
<button id="primary-cta" class="px-10 py-4 bg-accent-sage/60 hover:bg-accent-sage text-text-heading rounded-full text-base font-medium transition-all duration-300 min-w-[160px]">
                Get started
            </button>
<a class="group flex items-center gap-2 text-text-body hover:text-text-heading transition-colors text-base font-medium px-6 py-4" href="#install">
                How to install
                <span class="material-icons text-sm group-hover:translate-x-1 transition-transform">arrow_forward</span>
</a>
</div>
</div>
</header>
<section class="w-full py-20 px-6 border-t border-border-soft/50" id="what-is-penny">
<div class="max-w-5xl mx-auto space-y-16">
<div class="bg-card border border-border-soft rounded-2xl p-8 md:p-12 space-y-6">
<h2 class="text-3xl md:text-4xl font-serif font-medium text-text-heading">Frequently asked questions</h2>
<div class="space-y-6">
<div>
<h3 class="text-xl font-semibold text-text-heading">Is Penny an AI budgeting app?</h3>
<p class="text-text-body leading-relaxed mt-2">Yes. Penny is an AI-powered budgeting assistant that combines manual tracking, statement scanning, and calm, practical insights.</p>
</div>
<div>
<h3 class="text-xl font-semibold text-text-heading">Does Penny require bank account integration?</h3>
<p class="text-text-body leading-relaxed mt-2">No. Penny works without direct bank account connection. You can track manually or upload statements only when you choose.</p>
</div>
<div>
<h3 class="text-xl font-semibold text-text-heading">Is Penny secure and privacy-first?</h3>
<p class="text-text-body leading-relaxed mt-2">Yes. Penny is designed around user control. You decide what gets tracked, uploaded, and reviewed.</p>
</div>
<div>
<h3 class="text-xl font-semibold text-text-heading">Who uses Penny?</h3>
<p class="text-text-body leading-relaxed mt-2">Individuals, couples, families, freelancers, and privacy-conscious users who want clearer money awareness without complexity.</p>
</div>
<div>
<h3 class="text-xl font-semibold text-text-heading">How is Penny different from Mint or YNAB?</h3>
<p class="text-text-body leading-relaxed mt-2">Penny focuses on calm guidance, optional AI support, and a privacy-first workflow built for practical day-to-day clarity.</p>
</div>
</div>
</div>
</div>
</section>
<section class="py-24 px-6 text-center">
<div class="max-w-3xl mx-auto">
<h2 class="text-3xl md:text-4xl font-serif italic text-text-heading leading-tight mb-10">
            "Simplicity is the ultimate sophistication. Penny removes the clutter from your financial life."
        </h2>
<div class="w-16 h-0.5 bg-accent-sage mx-auto rounded-full opacity-60"></div>
</div>
</section>
<section class="w-full py-24 px-6 border-t border-border-soft/50" id="resources">
<div class="max-w-6xl mx-auto">
<div class="text-center mb-16">
<h2 class="text-4xl md:text-5xl font-serif font-medium text-text-heading mb-6">Budgeting resources</h2>
<p class="text-text-body text-lg max-w-xl mx-auto">A calm library for building money habits, exploring AI tools, and choosing the right budgeting approach.</p>
</div>
<div class="grid grid-cols-1 md:grid-cols-3 gap-8">
<a class="bg-card p-8 rounded-xl border border-border-soft hover:shadow-sm transition-all duration-300" href="/budgeting-app-guide">
<span class="text-[10px] uppercase tracking-widest font-semibold text-accent-label">Pillar guide</span>
<h3 class="text-2xl font-serif text-text-heading mt-4 mb-3">What Is the Best Budgeting App in 2026?</h3>
<p class="text-text-body leading-relaxed">A long-form guide to types of budgeting apps, privacy-first tools, and calm ways to choose what fits.</p>
</a>
<a class="bg-card p-8 rounded-xl border border-border-soft hover:shadow-sm transition-all duration-300" href="/blog/budgeting-without-bank-account">
<span class="text-[10px] uppercase tracking-widest font-semibold text-accent-label">Privacy</span>
<h3 class="text-2xl font-serif text-text-heading mt-4 mb-3">Budgeting without bank account access</h3>
<p class="text-text-body leading-relaxed">Manual tracking for people who want privacy and calm, without extra noise.</p>
</a>
<a class="bg-card p-8 rounded-xl border border-border-soft hover:shadow-sm transition-all duration-300" href="/blog/weekly-money-reflection">
<span class="text-[10px] uppercase tracking-widest font-semibold text-accent-label">Habits</span>
<h3 class="text-2xl font-serif text-text-heading mt-4 mb-3">Weekly budget review and monthly reflection</h3>
<p class="text-text-body leading-relaxed">A gentle rhythm for mindful money habits that actually last.</p>
</a>
</div>
</div>
</section>
<section class="w-full py-24 px-6" id="how">
<div class="max-w-7xl mx-auto">
<div class="text-center mb-20">
<h2 class="text-4xl md:text-5xl font-serif font-medium text-text-heading mb-6">How it works</h2>
<p class="text-text-body text-lg max-w-md mx-auto">A simple, three-step ritual to align your finances with your life goals.</p>
</div>
<div class="grid grid-cols-1 md:grid-cols-3 gap-8 lg:gap-12">
<div class="bg-card p-10 rounded-xl border border-border-soft hover:shadow-sm transition-all duration-300 group">
<div class="w-12 h-12 bg-canvas rounded-full flex items-center justify-center mb-8 text-text-heading font-serif text-xl italic group-hover:bg-accent-sand/30 transition-colors">1</div>
<h4 class="text-2xl font-serif text-text-heading mb-4">Begin</h4>
<p class="text-text-body leading-relaxed">Start where you are. Add your spending manually or upload what you already have. Your data stays private and in your control.</p>
</div>
<div class="bg-card p-10 rounded-xl border border-border-soft hover:shadow-sm transition-all duration-300 group">
<div class="w-12 h-12 bg-canvas rounded-full flex items-center justify-center mb-8 text-text-heading font-serif text-xl italic group-hover:bg-accent-sand/30 transition-colors">2</div>
<h4 class="text-2xl font-serif text-text-heading mb-4">Notice</h4>
<p class="text-text-body leading-relaxed">See patterns gently. Penny helps surface subscriptions and habits that may no longer serve you — without pressure or judgment.</p>
</div>
<div class="bg-card p-10 rounded-xl border border-border-soft hover:shadow-sm transition-all duration-300 group">
<div class="w-12 h-12 bg-canvas rounded-full flex items-center justify-center mb-8 text-text-heading font-serif text-xl italic group-hover:bg-accent-sand/30 transition-colors">3</div>
<h4 class="text-2xl font-serif text-text-heading mb-4">Grow</h4>
<p class="text-text-body leading-relaxed">Set simple intentions. Watch small, steady changes add up as your savings build and things begin to feel lighter.</p>
</div>
</div>
</div>
</section>
<section class="w-full py-24 px-6 relative overflow-hidden" id="install">
<div class="absolute inset-0 bg-gradient-to-b from-transparent to-accent-sage/10 pointer-events-none"></div>
<div class="max-w-6xl mx-auto relative z-10 text-center">
<div class="mb-16">
<span class="text-[10px] uppercase tracking-widest font-semibold text-accent-label mb-4 block">Zero Store Friction</span>
<h2 class="text-4xl md:text-5xl font-serif font-medium text-text-heading mb-6">App-like experience.</h2>
<p class="text-text-body text-lg max-w-lg mx-auto">
                Install Penny directly to your home screen as a Progressive Web App (PWA). No downloads, no updates, just instant access.
            </p>
</div>
<div class="flex justify-center">
<div class="w-full max-w-5xl bg-card p-10 md:p-12 rounded-2xl border border-border-soft shadow-sm">
<div class="grid gap-12 md:grid-cols-2 md:gap-16 items-start">
<div class="flex flex-col items-center text-center">
<div class="w-14 h-14 rounded-full bg-canvas flex items-center justify-center text-text-heading mb-4">
<span class="material-icons text-3xl">smartphone</span>
</div>
<h3 class="text-xl font-serif text-text-heading">Mobile Installation</h3>
<div class="mt-8 w-full max-w-md mx-auto space-y-6 text-left">
<div class="flex items-start gap-4">
<span class="flex-shrink-0 w-6 h-6 rounded-full bg-accent-sage/40 flex items-center justify-center text-xs font-semibold text-text-heading mt-0.5">1</span>
<p class="text-text-body text-sm leading-relaxed">
                        Open Penny in <span class="font-medium text-text-heading">Safari</span> (iOS) or <span class="font-medium text-text-heading">Chrome</span> (Android).
                    </p>
</div>
<div class="flex items-start gap-4">
<span class="flex-shrink-0 w-6 h-6 rounded-full bg-accent-sage/40 flex items-center justify-center text-xs font-semibold text-text-heading mt-0.5">2</span>
<p class="text-text-body text-sm leading-relaxed">
                        Tap the <span class="font-medium text-text-heading">Share</span> button or menu icon.
                    </p>
</div>
<div class="flex items-start gap-4">
<span class="flex-shrink-0 w-6 h-6 rounded-full bg-accent-sage/40 flex items-center justify-center text-xs font-semibold text-text-heading mt-0.5">3</span>
<p class="text-text-body text-sm leading-relaxed">
                        Select <span class="font-medium text-text-heading">Add to Home Screen</span>.
                    </p>
</div>
</div>
</div>
<div class="flex flex-col items-center text-center">
<div class="w-14 h-14 rounded-full bg-canvas flex items-center justify-center text-text-heading mb-4">
<span class="material-icons text-3xl">desktop_windows</span>
</div>
<h3 class="text-xl font-serif text-text-heading">Desktop Installation</h3>
<div class="mt-8 w-full max-w-md mx-auto space-y-6 text-left">
<div class="flex items-start gap-4">
<span class="flex-shrink-0 w-6 h-6 rounded-full bg-accent-sage/40 flex items-center justify-center text-xs font-semibold text-text-heading mt-0.5">1</span>
<p class="text-text-body text-sm leading-relaxed">
                        Visit the Penny dashboard in <span class="font-medium text-text-heading">Chrome</span> or <span class="font-medium text-text-heading">Edge</span>.
                    </p>
</div>
<div class="flex items-start gap-4">
<span class="flex-shrink-0 w-6 h-6 rounded-full bg-accent-sage/40 flex items-center justify-center text-xs font-semibold text-text-heading mt-0.5">2</span>
<p class="text-text-body text-sm leading-relaxed">
                        Look for the <span class="font-medium text-text-heading">Install</span> icon in the address bar.
                    </p>
</div>
<div class="flex items-start gap-4">
<span class="flex-shrink-0 w-6 h-6 rounded-full bg-accent-sage/40 flex items-center justify-center text-xs font-semibold text-text-heading mt-0.5">3</span>
<p class="text-text-body text-sm leading-relaxed">
                        Click <span class="font-medium text-text-heading">Install</span> to add Penny to your apps.
                    </p>
</div>
</div>
</div>
</div>
</div>
</div>
</div>
</section>
<section class="py-24 px-6 border-t border-border-soft/50" id="pricing">
<div class="max-w-7xl mx-auto">
<div class="mb-16 text-center">
<h2 class="text-4xl font-serif font-medium text-text-heading mb-4">Pricing</h2>
<p class="text-text-body">Choose the pace that feels right.</p>
</div>
<div class="flex justify-center mb-10">
<div class="billing-switch" data-billing-toggle>
<button type="button" data-billing="monthly" class="billing-label active">Monthly</button>
<button type="button" class="billing-toggle" data-billing-toggle-button aria-pressed="false">
<span class="billing-knob" aria-hidden="true"></span>
</button>
<button type="button" data-billing="annual" class="billing-label">Yearly <span class="billing-save">save 10%</span></button>
</div>
</div>
<div class="grid grid-cols-1 md:grid-cols-3 gap-8">
<div class="bg-card border border-border-soft p-10 rounded-xl flex flex-col h-full hover:shadow-md transition-all duration-300">
<div class="mb-auto">
<h3 class="text-lg font-medium text-accent-label mb-2 uppercase tracking-wide text-xs">Starter — Free</h3>
<p class="text-text-body mb-6">A quiet place to start.</p>
<div class="flex items-baseline gap-1 mb-8">
<span class="text-4xl font-serif text-text-heading" data-price data-monthly="$0" data-annual="$0">$0</span>
<span class="text-text-body/60 text-sm" data-unit data-monthly-unit="/month" data-annual-unit="/year">/month</span>
</div>
<ul class="space-y-4 text-sm text-text-body mb-8">
<li class="flex items-start gap-3">
<span class="material-icons text-base text-accent-sage">check_circle</span>
                            Access to all features in app
                        </li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-accent-sage">check_circle</span>
                            Receipt scanning: 5 scans / month (basic extraction)
                        </li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-accent-sage">check_circle</span>
                            Statement uploads: 2 / month, up to 30 days per upload
                        </li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-accent-sage">check_circle</span>
                            Insights: 2 weekly + 1 monthly per month
                        </li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-accent-sage">check_circle</span>
                            Chat: 10 messages / month (basic context)
                        </li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-accent-sage">check_circle</span>
                            Manual tracking always available
                        </li>
</ul>
<p class="text-xs text-text-body/70 mb-8">Great for getting started with calm, usage-based limits.</p>
</div>
<button class="w-full py-3 border border-border-soft rounded-full text-sm font-medium text-text-heading hover:bg-canvas transition-colors" data-plan="starter">
                    Start Free
                </button>
</div>
<div class="bg-card border border-accent-sage p-10 rounded-xl flex flex-col h-full relative overflow-hidden ring-1 ring-accent-sage/30 shadow-sm">
<div class="absolute top-0 right-0 px-4 py-1 bg-accent-sage/30 rounded-bl-xl text-xs font-semibold text-text-heading">Most Popular</div>
<div class="mb-auto">
<h3 class="text-lg font-medium text-primary-sage mb-2 uppercase tracking-wide text-xs">Pro</h3>
<p class="text-text-body mb-6">A little guidance goes a long way.</p>
<div class="flex items-baseline gap-1 mb-8">
<span class="text-4xl font-serif text-text-heading" data-price data-monthly="$15" data-annual="$162">$15</span>
<span class="text-text-body/60 text-sm" data-unit data-monthly-unit="/month" data-annual-unit="/year">/month</span>
</div>
<ul class="space-y-4 text-sm text-text-body mb-8">
<li class="flex items-start gap-3">
<span class="material-icons text-base text-primary-sage">check_circle</span>
<span class="text-text-heading font-medium">Everything in Starter</span>
</li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-primary-sage">check_circle</span>
                            Receipt scanning: 20 scans / month (full extraction)
</li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-primary-sage">check_circle</span>
                            Statement uploads: 10 / month, up to 6 months per upload
</li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-primary-sage">check_circle</span>
                            Insights: unlimited weekly check-ins
</li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-primary-sage">check_circle</span>
                            Insights: 10 daily + 4 monthly per month
</li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-primary-sage">check_circle</span>
                            Insights: 1 yearly overview per year
</li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-primary-sage">check_circle</span>
                            Chat: 25 messages / month
</li>
</ul>
<p class="text-xs text-text-body/70 mb-8">Built for regular use with generous limits and full feature access.</p>
</div>
<button class="w-full py-3 bg-text-heading text-white rounded-full text-sm font-medium hover:bg-text-heading/90 transition-colors shadow-sm" data-plan="pro">
                    Select Pro
                </button>
</div>
<div class="bg-card border border-border-soft p-10 rounded-xl flex flex-col h-full hover:shadow-md transition-all duration-300">
<div class="mb-auto">
<h3 class="text-lg font-medium text-accent-label mb-2 uppercase tracking-wide text-xs">Premium</h3>
<p class="text-text-body mb-6">Full support, zero pressure.</p>
<div class="flex items-baseline gap-1 mb-8">
<span class="text-4xl font-serif text-text-heading" data-price data-monthly="$25" data-annual="$270">$25</span>
<span class="text-text-body/60 text-sm" data-unit data-monthly-unit="/month" data-annual-unit="/year">/month</span>
</div>
<ul class="space-y-4 text-sm text-text-body mb-8">
<li class="flex items-start gap-3">
<span class="material-icons text-base text-accent-sage">check_circle</span>
<span class="text-text-heading font-medium">Everything in Pro</span>
</li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-accent-sage">check_circle</span>
                            Unlimited receipt scanning
</li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-accent-sage">check_circle</span>
                            Unlimited statement uploads
</li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-accent-sage">check_circle</span>
                            Unlimited daily, weekly, monthly, and yearly insights
</li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-accent-sage">check_circle</span>
                            Unlimited chat with Penny
</li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-accent-sage">check_circle</span>
                            Advanced insights and pattern guidance
</li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-accent-sage">check_circle</span>
                            No counters, ceilings, or limit prompts
</li>
</ul>
</div>
<button class="w-full py-3 border border-border-soft rounded-full text-sm font-medium text-text-heading hover:bg-canvas transition-colors" data-plan="premium">
                    Select Premium
                </button>
</div>
</div>
<p id="billing-error" class="text-xs text-text-body/70 mt-6 text-center"></p>
</div>
</section>
<section class="w-full py-24 px-6" id="faq">
<div class="max-w-3xl mx-auto">
<h2 class="text-3xl md:text-4xl font-serif font-medium text-text-heading mb-12 text-center">Frequently Asked Questions</h2>
<div class="space-y-4">
<details class="group border border-border-soft bg-card rounded-xl overflow-hidden open:ring-1 open:ring-accent-sage/30 transition-all">
<summary class="flex cursor-pointer items-center justify-between p-6 text-lg font-medium text-text-heading hover:bg-canvas/50 transition-colors">
                    Is Penny an AI budgeting app?
                    <span class="transition-transform duration-300 group-open:rotate-180 text-accent-label">
<span class="material-icons">expand_more</span>
</span>
</summary>
<div class="border-t border-border-soft px-6 py-6 text-text-body leading-relaxed bg-white/50">
                    Yes. Penny is an AI-powered budgeting assistant that helps individuals, couples, and families review spending, scan statements, and generate practical insights.
                </div>
</details>
<details class="group border border-border-soft bg-card rounded-xl overflow-hidden open:ring-1 open:ring-accent-sage/30 transition-all">
<summary class="flex cursor-pointer items-center justify-between p-6 text-lg font-medium text-text-heading hover:bg-canvas/50 transition-colors">
                    Does Penny require bank integration?
                    <span class="transition-transform duration-300 group-open:rotate-180 text-accent-label">
<span class="material-icons">expand_more</span>
</span>
</summary>
<div class="border-t border-border-soft px-6 py-6 text-text-body leading-relaxed bg-white/50">
                    No. Penny is designed to work without direct bank account integration. You can track manually and upload statements only when you choose.
                </div>
</details>
<details class="group border border-border-soft bg-card rounded-xl overflow-hidden open:ring-1 open:ring-accent-sage/30 transition-all">
<summary class="flex cursor-pointer items-center justify-between p-6 text-lg font-medium text-text-heading hover:bg-canvas/50 transition-colors">
                    Is Penny secure?
                    <span class="transition-transform duration-300 group-open:rotate-180 text-accent-label">
<span class="material-icons">expand_more</span>
</span>
</summary>
<div class="border-t border-border-soft px-6 py-6 text-text-body leading-relaxed bg-white/50">
                    Yes. Penny is privacy-first and keeps financial tracking under your control, with no forced bank syncing and no advertising-driven experience.
                </div>
</details>
<details class="group border border-border-soft bg-card rounded-xl overflow-hidden open:ring-1 open:ring-accent-sage/30 transition-all">
<summary class="flex cursor-pointer items-center justify-between p-6 text-lg font-medium text-text-heading hover:bg-canvas/50 transition-colors">
                    Can Penny help individuals budget?
                    <span class="transition-transform duration-300 group-open:rotate-180 text-accent-label">
<span class="material-icons">expand_more</span>
</span>
</summary>
<div class="border-t border-border-soft px-6 py-6 text-text-body leading-relaxed bg-white/50">
                    Yes. Individuals can use Penny for manual tracking, statement scans, and AI reflections that turn spending activity into clear next steps.
                </div>
</details>
<details class="group border border-border-soft bg-card rounded-xl overflow-hidden open:ring-1 open:ring-accent-sage/30 transition-all">
<summary class="flex cursor-pointer items-center justify-between p-6 text-lg font-medium text-text-heading hover:bg-canvas/50 transition-colors">
                    Is Penny good for couples?
                    <span class="transition-transform duration-300 group-open:rotate-180 text-accent-label">
<span class="material-icons">expand_more</span>
</span>
</summary>
<div class="border-t border-border-soft px-6 py-6 text-text-body leading-relaxed bg-white/50">
                    Yes. Couples use Penny to create shared visibility around spending and reduce money friction with calmer check-ins.
                </div>
</details>
<details class="group border border-border-soft bg-card rounded-xl overflow-hidden open:ring-1 open:ring-accent-sage/30 transition-all">
<summary class="flex cursor-pointer items-center justify-between p-6 text-lg font-medium text-text-heading hover:bg-canvas/50 transition-colors">
                    How is Penny different from Mint or YNAB?
                    <span class="transition-transform duration-300 group-open:rotate-180 text-accent-label">
<span class="material-icons">expand_more</span>
</span>
</summary>
<div class="border-t border-border-soft px-6 py-6 text-text-body leading-relaxed bg-white/50">
                    Penny is built around calm guidance, privacy-first workflows, and optional AI support. It emphasizes clarity without forcing complexity.
                </div>
</details>
</div>
</div>
</section>
@include('partials.marketing-footer')
@include('partials.marketing-scripts')
</body></html>
