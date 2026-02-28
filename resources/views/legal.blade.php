<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Penny — Privacy, Security, and Terms</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&amp;family=Playfair+Display:ital,wght@0,400;0,500;0,600;1,400&amp;display=swap" rel="stylesheet"/>
<script id="tailwind-config">
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    "canvas": "#F6F3EC",
                    "card": "#FFFEFA",
                    "text-heading": "#2F3A33",
                    "text-body": "#5E6B63",
                    "accent-sage": "#C7D4C6",
                    "accent-label": "#8E9A92",
                    "border-soft": "#E3E0D8",
                },
                fontFamily: {
                    "sans": ["Inter", "sans-serif"],
                    "serif": ["Playfair Display", "serif"],
                },
                borderRadius: {
                    "xl": "1.5rem",
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
        background: #F6F3EC;
    }
    ::-webkit-scrollbar-thumb {
        background: #E3E0D8;
        border-radius: 4px;
    }
    ::-webkit-scrollbar-thumb:hover {
        background: #C7D4C6;
    }
</style>
<link href="/marketing.css?v={{ filemtime(public_path('marketing.css')) }}" rel="stylesheet"/>
<link href="/marketing-overrides.css?v={{ filemtime(public_path('marketing-overrides.css')) }}" rel="stylesheet"/>
</head>
<body class="bg-canvas text-text-body font-sans antialiased selection:bg-accent-sage selection:text-text-heading">
<main class="max-w-4xl mx-auto px-6 py-20">
    <header class="mb-16">
        <h1 class="text-4xl md:text-5xl font-serif font-medium text-text-heading mb-4">Privacy, Security, and Terms</h1>
        <p class="text-text-body">Last updated: February 2026</p>
    </header>

    <section id="privacy" class="mb-16">
        <h2 class="text-3xl font-serif font-medium text-text-heading mb-6">Privacy Policy</h2>
        <p class="text-text-body mb-6">
            Penny is designed to help you understand your money without stress, judgment, or pressure. Your privacy and trust are essential to that mission.
        </p>
        <p class="text-text-body mb-10">
            This Privacy Policy explains what data Penny collects, how it is used, and how it is protected.
        </p>

        <h3 class="text-xl font-medium text-text-heading mb-4">Information We Collect</h3>
        <div class="space-y-8">
            <div>
                <h4 class="text-lg font-medium text-text-heading mb-2">Account Information</h4>
                <p class="text-text-body">When you create an account, we may collect:</p>
                <ul class="list-disc pl-6 text-text-body mt-2 space-y-1">
                    <li>Your name or nickname</li>
                    <li>Email address</li>
                    <li>Basic profile preferences</li>
                </ul>
            </div>
            <div>
                <h4 class="text-lg font-medium text-text-heading mb-2">Financial Data You Enter</h4>
                <p class="text-text-body">Penny allows you to manually add:</p>
                <ul class="list-disc pl-6 text-text-body mt-2 space-y-1">
                    <li>Transactions</li>
                    <li>Income</li>
                    <li>Categories</li>
                    <li>Notes and reflections</li>
                </ul>
                <p class="text-text-body mt-2">This data belongs to you.</p>
            </div>
            <div>
                <h4 class="text-lg font-medium text-text-heading mb-2">Uploaded Files (Receipts and Bank Statements)</h4>
                <p class="text-text-body">
                    If your plan includes uploads, you may choose to upload receipt images or bank statements (PDF or image).
                    These uploads are handled temporarily and securely.
                </p>
            </div>
        </div>

        <h3 class="text-xl font-medium text-text-heading mt-12 mb-4">How Uploaded Bank Statements Are Handled</h3>
        <p class="text-text-body mb-4">If you upload a bank statement, Penny uses it only to extract:</p>
        <ul class="list-disc pl-6 text-text-body space-y-1 mb-4">
            <li>Transaction amounts</li>
            <li>Transaction dates</li>
            <li>Basic descriptions (when available)</li>
        </ul>
        <p class="text-text-body mb-4">Once this information is extracted:</p>
        <ul class="list-disc pl-6 text-text-body space-y-1 mb-6">
            <li>The original bank statement file is immediately deleted</li>
            <li>Penny does not store full bank statements</li>
            <li>Penny does not retain bank account numbers</li>
            <li>Penny does not keep routing numbers or credentials</li>
        </ul>
        <p class="text-text-body mb-10">Penny never stores uploaded bank statements after processing.</p>

        <h3 class="text-xl font-medium text-text-heading mb-4">How We Use Your Data</h3>
        <p class="text-text-body mb-4">Your data is used to:</p>
        <ul class="list-disc pl-6 text-text-body space-y-1 mb-6">
            <li>Display spending summaries</li>
            <li>Generate insights (on supported plans)</li>
            <li>Help you track habits over time</li>
            <li>Improve the clarity and usefulness of the app</li>
        </ul>
        <p class="text-text-body mb-4">We do not:</p>
        <ul class="list-disc pl-6 text-text-body space-y-1 mb-10">
            <li>Sell your data</li>
            <li>Share your data with advertisers</li>
            <li>Run ads against your information</li>
        </ul>

        <h3 class="text-xl font-medium text-text-heading mb-4">AI Usage and Limitations</h3>
        <p class="text-text-body mb-4">Some Penny features use AI to generate:</p>
        <ul class="list-disc pl-6 text-text-body space-y-1 mb-6">
            <li>Weekly, monthly, or yearly insights</li>
            <li>Spending summaries</li>
            <li>Gentle suggestions</li>
        </ul>
        <p class="text-text-body mb-4">Important to know:</p>
        <ul class="list-disc pl-6 text-text-body space-y-1 mb-6">
            <li>AI works from the data you provide</li>
            <li>AI does not access deleted files</li>
            <li>AI does not see raw bank statements</li>
            <li>AI does not make financial decisions for you</li>
            <li>AI insights are meant to be supportive, not authoritative</li>
        </ul>
        <p class="text-text-body mb-10">
            Because AI is automated, it may occasionally misunderstand categories or make incorrect assumptions.
            It should never be treated as financial advice. You remain in control of your financial decisions.
        </p>

        <h3 class="text-xl font-medium text-text-heading mb-4">Data Retention</h3>
        <ul class="list-disc pl-6 text-text-body space-y-1 mb-10">
            <li>Transaction data you add stays in your account until you delete it</li>
            <li>Uploaded files are deleted immediately after processing</li>
            <li>You can delete your account at any time</li>
            <li>When an account is deleted, all associated data is permanently removed</li>
        </ul>

        <h3 class="text-xl font-medium text-text-heading mb-4">Your Rights</h3>
        <ul class="list-disc pl-6 text-text-body space-y-1 mb-10">
            <li>Access your data</li>
            <li>Correct your data</li>
            <li>Delete your data</li>
            <li>Export your data (on supported plans)</li>
        </ul>

        <h3 class="text-xl font-medium text-text-heading mb-2">Contact</h3>
        <p class="text-text-body">If you have questions about privacy: landonringeisen@gmail.com</p>
    </section>

    <section id="security" class="mb-16">
        <h2 class="text-3xl font-serif font-medium text-text-heading mb-6">Security</h2>
        <p class="text-text-body mb-6">Penny is built with privacy-first principles.</p>

        <h3 class="text-xl font-medium text-text-heading mb-4">Data Protection</h3>
        <p class="text-text-body mb-4">We use:</p>
        <ul class="list-disc pl-6 text-text-body space-y-1 mb-10">
            <li>Encrypted connections (HTTPS)</li>
            <li>Secure storage practices</li>
            <li>Limited internal access controls</li>
        </ul>
        <p class="text-text-body mb-10">Only essential services can access user data for functionality.</p>

        <h3 class="text-xl font-medium text-text-heading mb-4">Temporary File Handling</h3>
        <p class="text-text-body mb-4">Any uploaded file (receipts or statements):</p>
        <ul class="list-disc pl-6 text-text-body space-y-1 mb-10">
            <li>Is processed securely</li>
            <li>Is never reused</li>
            <li>Is deleted immediately after extraction</li>
        </ul>
        <p class="text-text-body mb-10">Penny does not archive uploaded documents.</p>

        <h3 class="text-xl font-medium text-text-heading mb-4">No Bank Credentials</h3>
        <p class="text-text-body mb-4">Penny does not:</p>
        <ul class="list-disc pl-6 text-text-body space-y-1">
            <li>Ask for bank logins</li>
            <li>Store bank usernames or passwords</li>
            <li>Maintain live bank connections</li>
        </ul>
    </section>

    <section id="terms">
        <h2 class="text-3xl font-serif font-medium text-text-heading mb-6">Terms of Service</h2>
        <p class="text-text-body mb-6">Last updated: February 2026</p>

        <h3 class="text-xl font-medium text-text-heading mb-4">Purpose of Penny</h3>
        <p class="text-text-body mb-6">
            Penny is a personal finance companion designed to help you understand spending habits, encourage calm financial awareness,
            and provide optional AI-powered insights. Penny is not a bank, accountant, or financial advisor.
        </p>

        <h3 class="text-xl font-medium text-text-heading mb-4">No Financial Advice</h3>
        <p class="text-text-body mb-6">
            All insights provided by Penny are informational only, should not be considered professional financial advice,
            and are meant to support awareness, not decision-making. You are responsible for all financial choices.
        </p>

        <h3 class="text-xl font-medium text-text-heading mb-4">AI Disclaimer</h3>
        <p class="text-text-body mb-4">Penny’s AI features:</p>
        <ul class="list-disc pl-6 text-text-body space-y-1 mb-6">
            <li>Are automated</li>
            <li>May contain inaccuracies</li>
            <li>Can misinterpret data</li>
            <li>Are based on patterns, not personal judgment</li>
        </ul>
        <p class="text-text-body mb-10">AI outputs should always be reviewed by you.</p>

        <h3 class="text-xl font-medium text-text-heading mb-4">Plan Limitations</h3>
        <div class="space-y-6 mb-10">
            <div>
                <h4 class="text-lg font-medium text-text-heading mb-2">Starter (Free)</h4>
                <ul class="list-disc pl-6 text-text-body space-y-1">
                    <li>Access to all features with usage limits</li>
                    <li>Receipt scanning: up to 5 scans per month</li>
                    <li>Bank statement uploads: up to 2 per month, up to 30 days per upload</li>
                    <li>Chat: up to 10 messages per month</li>
                    <li>Insights: 2 weekly and 1 monthly per month</li>
                </ul>
            </div>
            <div>
                <h4 class="text-lg font-medium text-text-heading mb-2">Pro</h4>
                <ul class="list-disc pl-6 text-text-body space-y-1">
                    <li>Access to all features with expanded limits</li>
                    <li>Receipt scanning: up to 20 scans per month</li>
                    <li>Bank statement uploads: up to 10 per month, up to 6 months per upload</li>
                    <li>Chat: up to 25 messages per month</li>
                    <li>Insights: unlimited weekly, plus monthly/daily/yearly limits by period</li>
                </ul>
            </div>
            <div>
                <h4 class="text-lg font-medium text-text-heading mb-2">Premium</h4>
                <ul class="list-disc pl-6 text-text-body space-y-1">
                    <li>Unlimited access across features</li>
                    <li>No usage counters or hard ceilings</li>
                    <li>Insights and chat without plan limits</li>
                    <li>Receipt scanning and statement uploads without plan limits</li>
                </ul>
            </div>
        </div>

        <h3 class="text-xl font-medium text-text-heading mb-4">Account Responsibility</h3>
        <p class="text-text-body mb-6">You are responsible for keeping your login secure, reviewing your data, and verifying accuracy.</p>

        <h3 class="text-xl font-medium text-text-heading mb-4">Termination</h3>
        <p class="text-text-body mb-6">You may cancel your account at any time. Penny may suspend accounts that violate usage terms.</p>

        <h3 class="text-xl font-medium text-text-heading mb-4">Limitation of Liability</h3>
        <p class="text-text-body mb-6">Penny is provided “as is.” We are not liable for financial losses or decisions made using the app.</p>

        <h3 class="text-xl font-medium text-text-heading mb-4">Governing Law</h3>
        <p class="text-text-body mb-6">These terms are governed by applicable local laws.</p>

        <h3 class="text-xl font-medium text-text-heading mb-2">Contact</h3>
        <p class="text-text-body">landonringeisen@gmail.com</p>
    </section>
</main>
</body>
</html>
