<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\AdminAnalyticsController;
use App\Http\Controllers\AdminFeedbackController;
use App\Http\Controllers\AdminImpersonationController;
use App\Http\Controllers\BankStatementController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\SavingsJourneyController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UsageController;
use App\Http\Controllers\WebauthnController;
use App\Http\Controllers\BillingController;
use Illuminate\Support\Facades\File;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/csrf', [AuthController::class, 'csrf']);
    Route::get('/updates', [FeedbackController::class, 'index']);
    Route::post('/updates/ideas', [FeedbackController::class, 'storeIdea'])->middleware('throttle:25,1');
    Route::post('/updates/bugs', [FeedbackController::class, 'storeBug'])->middleware('throttle:20,1');
    Route::get('/updates/items/{feedbackItem}', [FeedbackController::class, 'showItem']);
    Route::post('/updates/items/{feedbackItem}/comments', [FeedbackController::class, 'storeComment'])->middleware('throttle:40,1');
    Route::delete('/updates/items/{feedbackItem}/comments/{feedbackComment}', [FeedbackController::class, 'destroyComment'])->middleware(['auth', 'throttle:40,1']);
    Route::get('/updates/roadmap-items/{roadmapItem}', [FeedbackController::class, 'showRoadmapItem']);
    Route::post('/updates/roadmap-items/{roadmapItem}/comments', [FeedbackController::class, 'storeRoadmapComment'])->middleware('throttle:40,1');
    Route::delete('/updates/roadmap-items/{roadmapItem}/comments/{feedbackComment}', [FeedbackController::class, 'destroyRoadmapComment'])->middleware(['auth', 'throttle:40,1']);
    Route::get('/updates/announcements/{announcement}', [FeedbackController::class, 'showAnnouncement']);
    Route::post('/updates/announcements/{announcement}/comments', [FeedbackController::class, 'storeAnnouncementComment'])->middleware('throttle:40,1');
    Route::delete('/updates/announcements/{announcement}/comments/{feedbackComment}', [FeedbackController::class, 'destroyAnnouncementComment'])->middleware(['auth', 'throttle:40,1']);
    Route::post('/updates/items/{feedbackItem}/vote', [FeedbackController::class, 'vote'])->middleware('throttle:90,1');
    Route::get('/user/profile', [AuthController::class, 'profile'])->middleware('auth');
    Route::put('/user/profile', [AuthController::class, 'updateLifePhase'])->middleware(['auth', 'onboarding.activity', 'onboarding.readonly']);
    Route::post('/webauthn/authenticate/options', [WebauthnController::class, 'authenticateOptions']);
    Route::post('/webauthn/authenticate/verify', [WebauthnController::class, 'authenticateVerify']);
    Route::patch('/profile', [AuthController::class, 'updateProfile'])->middleware(['auth', 'onboarding.activity', 'onboarding.readonly']);
    Route::delete('/profile', [AuthController::class, 'destroy'])->middleware(['auth', 'onboarding.activity', 'onboarding.readonly']);
    Route::get('/data-summary', [AuthController::class, 'dataSummary'])->middleware('auth');
    Route::delete('/transactions/imported', [AuthController::class, 'deleteImportedTransactions'])->middleware(['auth', 'onboarding.activity', 'onboarding.readonly']);
    Route::delete('/transactions/all', [AuthController::class, 'deleteAllTransactions'])->middleware(['auth', 'onboarding.activity', 'onboarding.readonly']);

    Route::middleware(['auth', 'onboarding.activity'])->group(function () {
        Route::get('/onboarding/status', [OnboardingController::class, 'status']);
        Route::post('/onboarding/advance', [OnboardingController::class, 'advance']);
        Route::post('/onboarding/finish', [OnboardingController::class, 'finish']);
        Route::post('/onboarding/skip', [OnboardingController::class, 'skip']);
        Route::post('/onboarding/replay', [OnboardingController::class, 'replay']);

        Route::get('/webauthn/status', [WebauthnController::class, 'status']);
        Route::post('/webauthn/register/options', [WebauthnController::class, 'registerOptions'])->middleware('onboarding.readonly');
        Route::post('/webauthn/register/verify', [WebauthnController::class, 'registerVerify'])->middleware('onboarding.readonly');
        Route::delete('/webauthn', [WebauthnController::class, 'disable'])->middleware('onboarding.readonly');
        Route::get('/usage', [UsageController::class, 'show']);

        Route::post('/ai/monthly-reflection', [AiController::class, 'monthlyReflection'])->middleware('onboarding.step:3');
        Route::post('/ai/daily-overview', [AiController::class, 'dailyOverview'])->middleware('onboarding.step:3');
        Route::post('/ai/weekly-checkin', [AiController::class, 'weeklyCheckIn'])->middleware('onboarding.step:3');
        Route::post('/ai/yearly-reflection', [AiController::class, 'yearlyReflection'])->middleware('onboarding.step:3');
        Route::post('/ai/chat', [AiController::class, 'chat'])->middleware('onboarding.step:4');

        Route::post('/statements/upload', [BankStatementController::class, 'upload'])->middleware('onboarding.step:1');
        Route::post('/statements/scan-images', [BankStatementController::class, 'scanImages'])->middleware('onboarding.step:1');
        Route::get('/statements/{import}', [BankStatementController::class, 'show'])->middleware('onboarding.step:2');
        Route::post('/statements/{import}/confirm', [BankStatementController::class, 'confirm'])->middleware('onboarding.step:2');
        Route::delete('/statements/{import}', [BankStatementController::class, 'destroy'])->middleware('onboarding.step:2');

        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::get('/transactions/{transaction}', [TransactionController::class, 'show']);
        Route::post('/transactions', [TransactionController::class, 'store'])->middleware('onboarding.readonly');
        Route::put('/transactions/{transaction}', [TransactionController::class, 'update'])->middleware('onboarding.readonly');
        Route::delete('/transactions/{transaction}', [TransactionController::class, 'destroy'])->middleware('onboarding.readonly');

        Route::get('/savings-journeys', [SavingsJourneyController::class, 'index']);
        Route::post('/savings-journeys', [SavingsJourneyController::class, 'store'])->middleware('onboarding.readonly');
        Route::patch('/savings-journeys/{journey}', [SavingsJourneyController::class, 'update'])->middleware('onboarding.readonly');
        Route::post('/savings-journeys/{journey}/add', [SavingsJourneyController::class, 'add'])->middleware('onboarding.readonly');
        Route::delete('/savings-journeys/{journey}', [SavingsJourneyController::class, 'destroy'])->middleware('onboarding.readonly');
        Route::post('/savings-journeys/{journey}/pause', [SavingsJourneyController::class, 'pause'])->middleware('onboarding.readonly');
        Route::post('/savings-journeys/{journey}/resume', [SavingsJourneyController::class, 'resume'])->middleware('onboarding.readonly');
        Route::post('/savings-journeys/{journey}/complete', [SavingsJourneyController::class, 'complete'])->middleware('onboarding.readonly');
        Route::get('/savings-journeys/emergency-total', [SavingsJourneyController::class, 'emergencyTotal']);

        Route::post('/receipts/scan', [ReceiptController::class, 'scan'])->middleware('onboarding.readonly');
        Route::post('/receipts/scan-images', [ReceiptController::class, 'scanImages'])->middleware('onboarding.readonly');
        Route::get('/receipts/{receipt}', [ReceiptController::class, 'show']);
        Route::post('/receipts/{receipt}/confirm', [ReceiptController::class, 'confirm'])->middleware('onboarding.readonly');
        Route::delete('/receipts/{receipt}', [ReceiptController::class, 'destroy'])->middleware('onboarding.readonly');

        Route::get('/billing/plans', [BillingController::class, 'plans']);
        Route::get('/billing/status', [BillingController::class, 'status']);
        Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware('onboarding.readonly');
        Route::post('/billing/complete', [BillingController::class, 'complete'])->middleware('onboarding.readonly');
        Route::post('/billing/portal', [BillingController::class, 'portal']);
        Route::post('/billing/cancel', [BillingController::class, 'cancel'])->middleware('onboarding.readonly');
        Route::post('/billing/resume', [BillingController::class, 'resume'])->middleware('onboarding.readonly');
    });
});

Route::post('/admin/impersonate/stop', [AdminImpersonationController::class, 'stop'])
    ->middleware('auth');
Route::get('/admin/impersonate/stop', [AdminImpersonationController::class, 'stop'])
    ->middleware('auth');

Route::prefix('admin')->middleware(['auth', 'admin'])->group(function () {
    Route::post('/impersonate/{user}', [AdminImpersonationController::class, 'start']);
    Route::get('/users/network-data', [AdminAnalyticsController::class, 'networkData']);
    Route::get('/analytics/overview', [AdminAnalyticsController::class, 'overview']);
    Route::get('/analytics/growth', [AdminAnalyticsController::class, 'growth']);
    Route::get('/analytics/feature-usage', [AdminAnalyticsController::class, 'featureUsage']);
    Route::get('/analytics/users', [AdminAnalyticsController::class, 'users']);
    Route::post('/feedback-items', [AdminFeedbackController::class, 'createItem']);
    Route::get('/feedback-items', [AdminFeedbackController::class, 'index']);
    Route::get('/feedback-items/{feedbackItem}', [AdminFeedbackController::class, 'showItem']);
    Route::patch('/feedback-items/{feedbackItem}', [AdminFeedbackController::class, 'updateItem']);
    Route::delete('/feedback-items/{feedbackItem}', [AdminFeedbackController::class, 'destroyItem']);
    Route::post('/feedback-items/{feedbackItem}/promote', [AdminFeedbackController::class, 'promoteToRoadmap']);
    Route::post('/feedback-items/{feedbackItem}/responses', [AdminFeedbackController::class, 'postAdminResponse']);

    Route::post('/roadmap-items', [AdminFeedbackController::class, 'createRoadmapItem']);
    Route::patch('/roadmap-items/{roadmapItem}', [AdminFeedbackController::class, 'updateRoadmapItem']);
    Route::delete('/roadmap-items/{roadmapItem}', [AdminFeedbackController::class, 'destroyRoadmapItem']);
    Route::post('/roadmap-items/reorder', [AdminFeedbackController::class, 'reorderRoadmapItems']);

    Route::post('/announcements', [AdminFeedbackController::class, 'createAnnouncement']);
    Route::patch('/announcements/{announcement}', [AdminFeedbackController::class, 'updateAnnouncement']);
    Route::delete('/announcements/{announcement}', [AdminFeedbackController::class, 'destroyAnnouncement']);

    Route::get('/comments', [AdminFeedbackController::class, 'commentsIndex']);
    Route::patch('/comments/{comment}', [AdminFeedbackController::class, 'updateComment']);
    Route::delete('/comments/{comment}', [AdminFeedbackController::class, 'destroyComment']);
    Route::view('/dashboard', 'app');
    Route::view('/{any}', 'app')->where('any', '.*');
});

if (class_exists(\Laravel\Cashier\Cashier::class)) {
    Route::post('/stripe/webhook', [WebhookController::class, 'handleWebhook']);
}

Route::get('/sitemap.xml', function () {
    $today = now()->toDateString();
    $baseUrl = 'https://withpenny.app';

    $coreUrls = collect([
        '/',
        '/how-it-works',
        '/pricing',
        '/blog',
        '/faq',
        '/privacy',
        '/terms',
    ])->map(fn (string $path) => [
        'loc' => $baseUrl . ($path === '/' ? '' : $path),
        'lastmod' => $today,
        'changefreq' => in_array($path, ['/', '/blog'], true) ? 'weekly' : 'monthly',
        'priority' => match ($path) {
            '/' => '1.0',
            '/blog' => '0.8',
            '/pricing' => '0.9',
            default => '0.7',
        },
    ]);

    $blogUrls = collect(File::glob(resource_path('views/blog/*.blade.php')))
        ->filter(fn (string $file) => basename($file) !== 'index.blade.php')
        ->map(function (string $file) {
            $slug = basename($file, '.blade.php');
            return [
                'loc' => "https://withpenny.app/blog/{$slug}",
                'lastmod' => date('Y-m-d', filemtime($file)),
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ];
        });

    $allUrls = $coreUrls
        ->merge($blogUrls)
        ->unique('loc')
        ->values();

    $xml = collect([
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
    ])->merge(
        $allUrls->map(function (array $entry) {
            $loc = htmlspecialchars($entry['loc'], ENT_XML1);
            return <<<XML
  <url>
    <loc>{$loc}</loc>
    <lastmod>{$entry['lastmod']}</lastmod>
    <changefreq>{$entry['changefreq']}</changefreq>
    <priority>{$entry['priority']}</priority>
  </url>
XML;
        })
    )->push('</urlset>')->implode("\n");

    return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
});

Route::get('/', function () {
    $ua = strtolower(request()->userAgent() ?? '');
    $isMobile = str_contains($ua, 'mobile')
        || str_contains($ua, 'iphone')
        || str_contains($ua, 'ipad')
        || str_contains($ua, 'android');

    if (auth()->check() && $isMobile) {
        return redirect('/app');
    }

    return view('marketing');
});

Route::view('/app', 'app');

Route::view('/privacy', 'legal');
Route::view('/terms', 'legal');
Route::view('/security', 'legal');
Route::view('/login', 'app');
Route::view('/register', 'app');
Route::redirect('/updates', '/');
Route::view('/how-it-works', 'marketing');
Route::view('/pricing', 'marketing');
Route::view('/faq', 'marketing');
Route::view('/budgeting-app-guide', 'budgeting-app-guide');
Route::view('/blog', 'blog.index');
Route::view('/blog/privacy-budgeting-app', 'blog.privacy-budgeting-app');
Route::view('/blog/manual-budgeting-benefits', 'blog.manual-budgeting-benefits');
Route::view('/blog/ai-budgeting-tools', 'blog.ai-budgeting-tools');
Route::view('/blog/budgeting-for-anxiety', 'blog.budgeting-for-anxiety');
Route::view('/blog/budgeting-without-bank-account', 'blog.budgeting-without-bank-account');
Route::view('/blog/how-to-start-a-budget', 'blog.how-to-start-a-budget');
Route::view('/blog/receipt-scanning-budgeting-app', 'blog.receipt-scanning-budgeting-app');
Route::view('/blog/pwa-budgeting-apps', 'blog.pwa-budgeting-apps');
Route::view('/blog/50-30-20-budget-method', 'blog.50-30-20-budget-method');
Route::view('/blog/weekly-money-reflection', 'blog.weekly-money-reflection');
Route::view('/blog/ai-changing-personal-budgeting', 'blog.ai-changing-personal-budgeting');
Route::view('/blog/how-ai-is-changing-personal-budgeting', 'blog.ai-changing-personal-budgeting');
Route::view('/blog/budgeting-without-connecting-bank-account', 'blog.budgeting-without-connecting-bank-account');
Route::view('/blog/needs-wants-future-budgeting-framework-explained', 'blog.needs-wants-future-budgeting-framework-explained');
Route::view('/blog/scan-bank-statements-for-better-money-awareness', 'blog.scan-bank-statements-for-better-money-awareness');
Route::view('/blog/minimalist-budgeting-for-busy-professionals', 'blog.minimalist-budgeting-for-busy-professionals');
Route::view('/penny-vs-mint', 'compare.penny-vs-mint');
Route::view('/penny-vs-ynab', 'compare.penny-vs-ynab');
Route::view('/penny-vs-rocket-money', 'compare.penny-vs-rocket-money');
Route::get('/{any}', function (string $any) {
    if (preg_match('/\.[a-z0-9]+$/i', $any)) {
        abort(404);
    }

    return view('app');
})->where('any', '.*');
