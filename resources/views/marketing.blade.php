<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Penny — Your Calm Money Companion</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#13ec37",
                        "background-light": "#f6f8f6", 
                        "background-cream": "#F9F9F7", 
                        "background-dark": "#102213",
                    },
                    fontFamily: {
                        "display": ["Inter", "sans-serif"]
                    },
                    borderRadius: {"DEFAULT": "0.25rem", "lg": "0.5rem", "xl": "0.75rem", "full": "9999px"},
                    spacing: {
                        '128': '32rem',
                    }
                },
            },
        }
    </script>
<style>
        ::-webkit-scrollbar {
            width: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #F9F9F7;
        }
        ::-webkit-scrollbar-thumb {
            background: #e5e5e5;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #d4d4d4;
        }
        .grid-lines {
            background-image: linear-gradient(to right, rgba(0,0,0,0.05) 1px, transparent 1px),
                              linear-gradient(to bottom, rgba(0,0,0,0.05) 1px, transparent 1px);
            background-size: 20px 20px;
        }
        summary::-webkit-details-marker { display: none; }
    </style>
</head>
<body class="bg-background-cream text-neutral-900 font-display antialiased selection:bg-primary selection:text-black">
<nav class="w-full border-b border-neutral-200 bg-background-cream sticky top-0 z-50">
<div class="max-w-7xl mx-auto px-6 h-20 flex items-center justify-between">
<a class="flex items-center gap-2 group cursor-pointer" href="/">
<div class="w-4 h-4 bg-neutral-900 group-hover:bg-primary transition-colors duration-300 rounded-sm"></div>
<span class="text-xl font-bold tracking-tight">Penny.</span>
</a>
<div class="hidden md:flex gap-8 text-sm font-medium text-neutral-600">
<a class="hover:text-black transition-colors" href="/manifesto">Manifesto</a>
<a class="hover:text-black transition-colors" href="/#features">Features</a>
<a class="hover:text-black transition-colors" href="/#pricing">Pricing</a>
</div>
<a id="login-link" class="px-6 py-2 border border-neutral-300 rounded text-sm font-medium hover:border-primary hover:text-primary transition-colors duration-300" href="/login">
                Login
            </a>
</div>
</nav>
<header class="relative w-full border-b border-neutral-200 overflow-hidden min-h-screen flex items-center">
<div class="absolute top-0 right-1/4 h-full w-px bg-neutral-200 hidden lg:block opacity-40"></div>
<div class="absolute bottom-0 left-1/3 h-1/2 w-px bg-neutral-200 hidden lg:block opacity-40"></div>
<div class="max-w-7xl mx-auto px-6 py-20 lg:py-24 relative z-10 flex flex-col items-center text-center w-full">
<div class="inline-flex items-center gap-2 mb-8">
<span class="w-2 h-2 rounded-full bg-primary"></span>
<span class="text-xs uppercase tracking-widest font-semibold text-neutral-500">Finance Simplified</span>
</div>
<h1 class="text-6xl lg:text-8xl font-bold tracking-tight leading-[0.95] text-neutral-900 mb-8 max-w-5xl">
            Penny — your <br/>
<span class="text-neutral-400">calm money</span> <br/>
            companion.
        </h1>
<p class="text-xl text-neutral-600 max-w-xl leading-relaxed mb-10 mx-auto">
            Master your finances without the noise. A strict, minimalist approach to personal wealth management designed for peace of mind.
        </p>
<div class="flex flex-wrap justify-center gap-4">
<a id="primary-cta" class="px-8 py-4 border border-neutral-900 rounded text-base font-medium hover:bg-primary hover:border-primary hover:text-neutral-900 transition-all duration-300" href="/get-penny">
                Start Free Trial
            </a>
<a class="px-8 py-4 border border-transparent rounded text-base font-medium text-neutral-500 hover:text-neutral-900 transition-colors duration-300" href="/manifesto">
                Read the philosophy →
            </a>
</div>
</div>
</header>
<section id="features" class="w-full border-b border-neutral-200 bg-background-cream">
<div class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-neutral-200">
<div class="p-12 hover:bg-white transition-colors duration-500 group">
<span class="block text-6xl font-bold text-neutral-200 group-hover:text-primary transition-colors duration-300 mb-8">01</span>
<h3 class="text-2xl font-bold mb-4">Expense Tracking</h3>
<p class="text-neutral-600 leading-relaxed">
                    Automated categorization that respects your time. We filter the noise so you can focus on where your money actually goes.
                </p>
<div class="mt-8 w-8 h-px bg-neutral-300 group-hover:w-16 group-hover:bg-primary transition-all duration-300"></div>
</div>
<div class="p-12 hover:bg-white transition-colors duration-500 group">
<span class="block text-6xl font-bold text-neutral-200 group-hover:text-primary transition-colors duration-300 mb-8">02</span>
<h3 class="text-2xl font-bold mb-4">Goal Setting</h3>
<p class="text-neutral-600 leading-relaxed">
                    Visual arcs to help you reach the finish line. Whether it's a rainy day fund or a dream home, visualize the path clearly.
                </p>
<div class="mt-8 w-8 h-px bg-neutral-300 group-hover:w-16 group-hover:bg-primary transition-all duration-300"></div>
</div>
<div class="p-12 hover:bg-white transition-colors duration-500 group">
<span class="block text-6xl font-bold text-neutral-200 group-hover:text-primary transition-colors duration-300 mb-8">03</span>
<h3 class="text-2xl font-bold mb-4">Wealth Insights</h3>
<p class="text-neutral-600 leading-relaxed">
                    Monthly reports that speak your language, not bank jargon. Understanding your net worth should be simple and empowering.
                </p>
<div class="mt-8 w-8 h-px bg-neutral-300 group-hover:w-16 group-hover:bg-primary transition-all duration-300"></div>
</div>
</div>
</section>
<section class="py-24 px-6 bg-background-cream">
<div class="max-w-7xl mx-auto">
<h2 class="text-4xl font-bold mb-4">Why Penny</h2>
<p class="text-neutral-600 leading-relaxed max-w-3xl">
Penny is for people who feel stressed, avoidant, or overwhelmed by money. It gives you a calm place to
look at what’s real — without shame, pressure, or judgment.
</p>
<p class="text-neutral-600 leading-relaxed max-w-3xl mt-4">
It’s built for small moments: logging a purchase, checking in after a paycheck, or looking at money
only when you’re ready.
</p>
</div>
</section>
<section class="py-24 px-6 bg-background-cream">
<div class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-2 gap-12">
<div>
<h3 class="text-2xl font-bold mb-4">How it works</h3>
<ul class="space-y-3 text-neutral-600">
<li>Add spending or income moments on your phone.</li>
<li>See patterns gently, without overwhelm.</li>
<li>Save without pressure.</li>
<li>Talk with Penny AI for calm guidance.</li>
</ul>
</div>
<div>
<h3 class="text-2xl font-bold mb-4">Mobile-first, on purpose</h3>
<p class="text-neutral-600 leading-relaxed">
The full experience lives on your phone. Desktop is for learning and reading.
</p>
<p class="text-neutral-600 leading-relaxed mt-4">
This is intentional — Penny is built for quick moments, camera capture, and habit‑building on the go.
</p>
</div>
</div>
</section>
<section class="py-16 px-6 bg-background-cream">
<div class="max-w-7xl mx-auto">
<div class="border border-neutral-200 rounded-lg p-8 bg-white">
<h3 class="text-2xl font-bold mb-4">Offline support</h3>
<p class="text-neutral-600 leading-relaxed max-w-3xl">
Penny works without service. You can add spending, scan receipts, and review history offline. Everything
syncs automatically when you’re back online. Penny AI works once you’re connected again.
</p>
<div class="mt-6 flex flex-wrap gap-3 text-sm text-neutral-600">
<span class="px-3 py-1 border border-neutral-200 rounded-full">Installs like an app</span>
<span class="px-3 py-1 border border-neutral-200 rounded-full">No app store</span>
<span class="px-3 py-1 border border-neutral-200 rounded-full">Fast and lightweight</span>
</div>
</div>
</div>
</section>
<section class="py-24 px-6 bg-background-cream">
<div class="max-w-7xl mx-auto">
<div class="grid grid-cols-1 md:grid-cols-2 gap-8">
<div class="border border-neutral-200 rounded-lg p-8 bg-white">
<h3 class="text-2xl font-bold mb-4">Install Penny (iPhone)</h3>
<ol class="space-y-2 text-neutral-600 list-decimal list-inside">
<li>Open Penny in Safari</li>
<li>Tap Share</li>
<li>Tap “Add to Home Screen”</li>
</ol>
</div>
<div class="border border-neutral-200 rounded-lg p-8 bg-white">
<h3 class="text-2xl font-bold mb-4">Install Penny (Android)</h3>
<ol class="space-y-2 text-neutral-600 list-decimal list-inside">
<li>Open Penny in Chrome</li>
<li>Tap the menu</li>
<li>Tap “Install App”</li>
</ol>
</div>
</div>
<p class="text-neutral-600 mt-6">Installing Penny unlocks the full experience.</p>
</div>
</section>
<section class="py-16 px-6 bg-background-cream">
<div class="max-w-7xl mx-auto">
<h3 class="text-2xl font-bold mb-6">FAQ</h3>
<div class="space-y-4 text-neutral-600 faq-accordion">
<details class="border border-neutral-200 rounded-lg p-4 bg-white">
<summary class="font-semibold text-neutral-900 cursor-pointer list-none flex items-center justify-between">
<span>Is Penny free?</span>
<span class="material-icons text-base text-neutral-400">add</span>
</summary>
<p class="mt-2">Yes. Starter is free and stays that way. You can upgrade anytime.</p>
</details>
<details class="border border-neutral-200 rounded-lg p-4 bg-white">
<summary class="font-semibold text-neutral-900 cursor-pointer list-none flex items-center justify-between">
<span>Is my data safe?</span>
<span class="material-icons text-base text-neutral-400">add</span>
</summary>
<p class="mt-2">Yes. Your data stays yours. Penny doesn’t sell it or run ads.</p>
</details>
<details class="border border-neutral-200 rounded-lg p-4 bg-white">
<summary class="font-semibold text-neutral-900 cursor-pointer list-none flex items-center justify-between">
<span>Do I need to link a bank?</span>
<span class="material-icons text-base text-neutral-400">add</span>
</summary>
<p class="mt-2">No. Penny works fully without bank connections.</p>
</details>
<details class="border border-neutral-200 rounded-lg p-4 bg-white">
<summary class="font-semibold text-neutral-900 cursor-pointer list-none flex items-center justify-between">
<span>Does Penny judge me?</span>
<span class="material-icons text-base text-neutral-400">add</span>
</summary>
<p class="mt-2">No. Penny is built to be calm, kind, and honest — never shaming.</p>
</details>
<details class="border border-neutral-200 rounded-lg p-4 bg-white">
<summary class="font-semibold text-neutral-900 cursor-pointer list-none flex items-center justify-between">
<span>Can I use it offline?</span>
<span class="material-icons text-base text-neutral-400">add</span>
</summary>
<p class="mt-2">Yes. You can keep going offline and it syncs when you’re back.</p>
</details>
</div>
</div>
</section>
<section class="py-32 px-6 bg-neutral-900 text-white text-center relative overflow-hidden">
<div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(#13ec37 1px, transparent 1px); background-size: 40px 40px;"></div>
<div class="max-w-4xl mx-auto relative z-10">
<h2 class="text-3xl md:text-5xl font-light leading-tight">
                "Simplicity is the ultimate sophistication. Penny removes the clutter from your financial life."
            </h2>
<div class="mt-8 w-12 h-1 bg-primary mx-auto rounded-full"></div>
</div>
</section>
<section id="pricing" class="py-24 px-6 bg-background-cream">
<div class="max-w-7xl mx-auto">
<div class="mb-16 grid grid-cols-1 md:grid-cols-2 gap-8 items-end border-b border-neutral-200 pb-8">
<div>
<h2 class="text-4xl font-bold mb-4">Transparent Pricing.</h2>
<p class="text-neutral-500">No hidden fees. No surprises. Just calm.</p>
</div>
<div class="md:text-right">
<span class="inline-block px-3 py-1 bg-primary/20 text-green-800 text-xs font-bold uppercase tracking-wider rounded-full">Annual Billing - Save 20%</span>
</div>
</div>
<div class="grid grid-cols-1 md:grid-cols-3 gap-8">
<div class="bg-white border border-neutral-200 p-8 rounded-lg flex flex-col h-full hover:border-neutral-400 transition-colors duration-300">
<div class="mb-auto">
<h3 class="text-lg font-medium text-neutral-500 mb-2">Basic</h3>
<div class="flex items-baseline gap-1 mb-6">
<span class="text-4xl font-bold text-neutral-900">$0</span>
<span class="text-neutral-400">/mo</span>
</div>
<ul class="space-y-4 text-sm text-neutral-600 mb-8">
<li class="flex items-start gap-3">
<span class="material-icons text-base text-neutral-400">check</span>
                                Manual transaction entry
                            </li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-neutral-400">check</span>
                                Basic monthly overview
                            </li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-neutral-400">check</span>
                                1 Savings Goal
                            </li>
</ul>
</div>
<a data-plan="starter" class="plan-cta w-full py-3 border border-neutral-200 rounded text-sm font-medium text-center pointer-events-none cursor-default" href="javascript:void(0)" aria-disabled="true" tabindex="-1">
                        Start Basic
                    </a>
</div>
<div class="bg-white border border-neutral-200 p-8 rounded-lg flex flex-col h-full relative overflow-hidden group hover:border-primary transition-colors duration-300">
<div class="absolute top-0 right-0 w-16 h-16 bg-primary/10 rounded-bl-full -mr-8 -mt-8"></div>
<div class="mb-auto">
<h3 class="text-lg font-medium text-neutral-900 mb-2">Pro</h3>
<div class="flex items-baseline gap-1 mb-6">
<span class="text-4xl font-bold text-neutral-900">$10</span>
<span class="text-neutral-400">/mo</span>
</div>
<ul class="space-y-4 text-sm text-neutral-600 mb-8">
<li class="flex items-start gap-3">
<span class="material-icons text-base text-primary">check</span>
<strong>Everything in Basic</strong>
</li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-primary">check</span>
                                Bank Sync (US &amp; Canada)
                            </li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-primary">check</span>
                                Unlimited Goals
                            </li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-primary">check</span>
                                Custom Categories
                            </li>
</ul>
</div>
<a data-plan="pro" class="plan-cta w-full py-3 bg-neutral-900 text-white rounded text-sm font-medium text-center pointer-events-none cursor-default border border-transparent" href="javascript:void(0)" aria-disabled="true" tabindex="-1">
                        Select Pro
                    </a>
</div>
<div class="bg-white border border-neutral-200 p-8 rounded-lg flex flex-col h-full hover:border-neutral-400 transition-colors duration-300">
<div class="mb-auto">
<h3 class="text-lg font-medium text-neutral-500 mb-2">Wealth</h3>
<div class="flex items-baseline gap-1 mb-6">
<span class="text-4xl font-bold text-neutral-900">$25</span>
<span class="text-neutral-400">/mo</span>
</div>
<ul class="space-y-4 text-sm text-neutral-600 mb-8">
<li class="flex items-start gap-3">
<span class="material-icons text-base text-neutral-400">check</span>
<strong>Everything in Pro</strong>
</li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-neutral-400">check</span>
                                Investment Tracking
                            </li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-neutral-400">check</span>
                                Priority Concierge Support
                            </li>
<li class="flex items-start gap-3">
<span class="material-icons text-base text-neutral-400">check</span>
                                Financial Advisor Export
                            </li>
</ul>
</div>
<a data-plan="premium" class="plan-cta w-full py-3 border border-neutral-200 rounded text-sm font-medium text-center pointer-events-none cursor-default" href="javascript:void(0)" aria-disabled="true" tabindex="-1">
                        Select Wealth
                    </a>
</div>
</div>
</div>
</section>
<footer class="bg-background-cream border-t border-neutral-200 pt-16 pb-8">
<div class="max-w-7xl mx-auto px-6">
<div class="grid grid-cols-2 md:grid-cols-4 gap-12 mb-16">
<div class="col-span-2 md:col-span-1">
<div class="flex items-center gap-2 mb-6">
<div class="w-3 h-3 bg-neutral-900 rounded-sm"></div>
<span class="text-lg font-bold tracking-tight">Penny.</span>
</div>
<p class="text-sm text-neutral-500">
                        Design by Swiss principles. <br/>
                        Built for calm.
                    </p>
</div>
<div>
<h4 class="font-bold text-neutral-900 mb-4">Product</h4>
<ul class="space-y-2 text-sm text-neutral-500">
<li><a class="hover:text-primary transition-colors" href="/#features">Features</a></li>
<li><a class="hover:text-primary transition-colors" href="/#pricing">Pricing</a></li>
<li><a class="hover:text-primary transition-colors" href="/updates">Updates</a></li>
</ul>
</div>
<div>
<h4 class="font-bold text-neutral-900 mb-4">Company</h4>
<ul class="space-y-2 text-sm text-neutral-500">
<li><a class="hover:text-primary transition-colors" href="/about">About</a></li>
<li><a class="hover:text-primary transition-colors" href="/manifesto">Manifesto</a></li>
<li><a class="hover:text-primary transition-colors" href="/careers">Careers</a></li>
</ul>
</div>
<div>
<h4 class="font-bold text-neutral-900 mb-4">Legal</h4>
<ul class="space-y-2 text-sm text-neutral-500">
<li><a class="hover:text-primary transition-colors" href="/privacy">Privacy</a></li>
<li><a class="hover:text-primary transition-colors" href="/terms">Terms</a></li>
<li><a class="hover:text-primary transition-colors" href="/security">Security</a></li>
</ul>
</div>
</div>
<div class="border-t border-neutral-200 pt-8 flex flex-col md:flex-row justify-between items-center text-xs text-neutral-400">
<p>© 2023 Penny Financial Inc. All rights reserved.</p>
<div class="flex gap-4 mt-4 md:mt-0">
<a class="hover:text-neutral-900" href="/twitter">Twitter</a>
<a class="hover:text-neutral-900" href="/instagram">Instagram</a>
<a class="hover:text-neutral-900" href="/linkedin">LinkedIn</a>
</div>
</div>
</div>
</footer>
@php
    $isAuthed = auth()->check();
@endphp
<script>
    (function () {
        const isAuthed = {{ $isAuthed ? 'true' : 'false' }};
        const ua = navigator.userAgent.toLowerCase();
        const isMobile = ua.includes('mobile') || ua.includes('iphone') || ua.includes('ipad') || ua.includes('android');

        const loginLink = document.getElementById('login-link');
        if (loginLink && isAuthed) {
            loginLink.setAttribute('href', '/app');
        }

        const primaryCta = document.getElementById('primary-cta');
        if (primaryCta) {
            primaryCta.setAttribute('href', isMobile ? '/register' : '/get-penny');
        }

        document.querySelectorAll('a[href^=\"/#\"], a[href^=\"#\"]').forEach((link) => {
            link.addEventListener('click', (event) => {
                const href = link.getAttribute('href') || '';
                const hashIndex = href.indexOf('#');
                if (hashIndex === -1) return;
                const targetId = href.slice(hashIndex + 1);
                if (!targetId) return;
                const target = document.getElementById(targetId);
                if (!target) return;
                event.preventDefault();
                history.replaceState(null, '', `#${targetId}`);
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        document.querySelectorAll('.faq-accordion details').forEach((detail) => {
            detail.addEventListener('toggle', () => {
                if (!detail.open) return;
                document.querySelectorAll('.faq-accordion details').forEach((other) => {
                    if (other !== detail) other.open = false;
                });
            });
        });
    })();
</script>
</body></html>
