<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\BankStatementImport;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SpreadsheetExportService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class SpreadsheetExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);

        config([
            'subscriptions.subscription_name' => 'default',
            'subscriptions.plans.pro.monthly.stripe_price' => 'price_pro_monthly_test',
            'subscriptions.plans.pro.yearly.stripe_price' => 'price_pro_yearly_test',
            'subscriptions.plans.premium.monthly.stripe_price' => 'price_premium_monthly_test',
            'subscriptions.plans.premium.yearly.stripe_price' => 'price_premium_yearly_test',
        ]);
    }

    public function test_spreadsheet_export_requires_authentication(): void
    {
        $this->postJson('/api/spreadsheets/generate')
            ->assertUnauthorized();
    }

    public function test_successful_spreadsheet_export_records_usage_only_after_generation(): void
    {
        $user = User::factory()->create();
        $this->createTransaction($user, '2026-03-03', 'Payroll Deposit', 2200.00, 'Income', 'income', 'manual');
        $this->createTransaction($user, '2026-03-08', 'Grocery Store', 185.45, 'Groceries', 'spending', 'manual');

        $response = $this->actingAs($user)->post('/api/spreadsheets/generate');

        $response
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertStringContainsString(
            'attachment; filename="penny-budget-',
            (string) $response->headers->get('content-disposition')
        );

        $this->assertDatabaseHas('analytics_events', [
            'user_id' => $user->id,
            'event_name' => 'spreadsheet_generated',
        ]);

        $this->actingAs($user)
            ->getJson('/api/usage')
            ->assertOk()
            ->assertJsonPath('features.spreadsheet_exports.used', 1)
            ->assertJsonPath('features.spreadsheet_exports.remaining', 0);
    }

    public function test_limit_reached_returns_403_and_does_not_add_extra_usage_event(): void
    {
        $user = User::factory()->create();

        AnalyticsEvent::query()->create([
            'user_id' => $user->id,
            'event_name' => 'spreadsheet_generated',
            'event_data' => ['source' => 'test'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/api/spreadsheets/generate')
            ->assertStatus(403)
            ->assertJsonPath('message', "You've reached your monthly limit.");

        $this->assertSame(
            1,
            AnalyticsEvent::query()
                ->where('user_id', $user->id)
                ->where('event_name', 'spreadsheet_generated')
                ->count()
        );
    }

    public function test_generation_failure_does_not_decrement_usage(): void
    {
        $user = User::factory()->create();

        $this->mock(SpreadsheetExportService::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andThrow(new RuntimeException('generation failed'));
        });

        $this->actingAs($user)
            ->postJson('/api/spreadsheets/generate')
            ->assertStatus(500)
            ->assertJsonPath('message', 'We could not generate your spreadsheet right now. Please try again in a moment.');

        $this->assertDatabaseMissing('analytics_events', [
            'user_id' => $user->id,
            'event_name' => 'spreadsheet_generated',
        ]);
    }

    public function test_export_includes_only_authenticated_users_non_demo_confirmed_transactions(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->createTransaction($user, '2026-03-04', 'KEEP THIS ROW', 42.55, 'Dining', 'spending', 'manual');
        $this->createTransaction($user, '2026-03-05', 'DROP DEMO ROW', 19.99, 'Misc', 'spending', 'demo');
        $this->createTransaction($otherUser, '2026-03-06', 'DROP OTHER USER', 120.00, 'Groceries', 'spending', 'manual');

        BankStatementImport::query()->create([
            'user_id' => $user->id,
            'transactions' => [
                [
                    'id' => 'pending-row',
                    'date' => '2026-03-07',
                    'description' => 'UNCONFIRMED ROW',
                    'amount' => 20.00,
                    'type' => 'spending',
                    'category' => 'Misc',
                    'include' => true,
                ],
            ],
            'meta' => null,
            'masked_account' => null,
            'source' => 'pdf_text',
        ]);

        $response = $this->actingAs($user)->post('/api/spreadsheets/generate');
        $response->assertOk();

        $sharedStringsXml = $this->sharedStringsXmlFromBinary((string) $response->getContent());

        $this->assertStringContainsString('KEEP THIS ROW', $sharedStringsXml);
        $this->assertStringNotContainsString('DROP DEMO ROW', $sharedStringsXml);
        $this->assertStringNotContainsString('DROP OTHER USER', $sharedStringsXml);
        $this->assertStringNotContainsString('UNCONFIRMED ROW', $sharedStringsXml);
    }

    public function test_pro_export_with_multi_month_range_includes_monthly_snapshot_sheet(): void
    {
        $user = User::factory()->create();
        $this->createSubscription($user, 'price_pro_monthly_test');

        $this->createTransaction($user, '2026-01-10', 'January Payroll', 2000.00, 'Income', 'income', 'manual');
        $this->createTransaction($user, '2026-02-10', 'February Grocery', 200.00, 'Groceries', 'spending', 'manual');

        $response = $this->actingAs($user)->postJson('/api/spreadsheets/generate', [
            'start_date' => '2026-01-01',
            'end_date' => '2026-02-28',
        ]);
        $response->assertOk();

        $workbookXml = $this->workbookXmlFromBinary((string) $response->getContent());
        $this->assertStringContainsString('name="Monthly Snapshot"', $workbookXml);
    }

    public function test_export_with_no_transactions_still_returns_structured_workbook(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/api/spreadsheets/generate');
        $response->assertOk();

        $sharedStringsXml = $this->sharedStringsXmlFromBinary((string) $response->getContent());
        $this->assertStringContainsString('No transactions in this period.', $sharedStringsXml);
        $this->assertStringContainsString('No spending entries in this period.', $sharedStringsXml);
    }

    public function test_date_range_defaults_to_all_user_transactions_when_no_dates_are_provided(): void
    {
        $user = User::factory()->create();

        $this->createTransaction($user, '2026-01-10', 'JANUARY ROW', 120.00, 'Dining', 'spending', 'manual');
        $this->createTransaction($user, '2026-03-05', 'MARCH ROW', 90.00, 'Groceries', 'spending', 'manual');
        $this->createTransaction($user, '2026-04-02', 'APRIL INCOME', 2200.00, 'Income', 'income', 'manual');

        $response = $this->actingAs($user)->postJson('/api/spreadsheets/generate');
        $response->assertOk();

        $sharedStringsXml = $this->sharedStringsXmlFromBinary((string) $response->getContent());
        $this->assertStringContainsString('JANUARY ROW', $sharedStringsXml);
        $this->assertStringContainsString('MARCH ROW', $sharedStringsXml);

        $overviewSheetXml = $this->sheetXmlFromBinary((string) $response->getContent(), 1);
        $this->assertStringContainsString('<v>2200.0</v>', $overviewSheetXml);
    }

    public function test_date_range_defaults_to_current_month_when_only_one_date_is_provided(): void
    {
        Carbon::setTestNow('2026-03-15 10:00:00');

        try {
            $user = User::factory()->create();

            $this->createTransaction($user, '2026-03-04', 'KEEP CURRENT MONTH', 60.00, 'Dining', 'spending', 'manual');
            $this->createTransaction($user, '2026-01-10', 'DROP OUTSIDE MONTH', 25.00, 'Dining', 'spending', 'manual');

            $response = $this->actingAs($user)->postJson('/api/spreadsheets/generate', [
                'start_date' => '2026-01-01',
            ]);
            $response->assertOk();

            $sharedStringsXml = $this->sharedStringsXmlFromBinary((string) $response->getContent());
            $this->assertStringContainsString('KEEP CURRENT MONTH', $sharedStringsXml);
            $this->assertStringNotContainsString('DROP OUTSIDE MONTH', $sharedStringsXml);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function createTransaction(
        User $user,
        string $date,
        string $note,
        float $amount,
        string $category,
        string $type,
        string $source
    ): Transaction {
        return Transaction::query()->create([
            'user_id' => $user->id,
            'amount' => $amount,
            'category' => $category,
            'note' => $note,
            'transaction_date' => $date,
            'source' => $source,
            'type' => $type,
        ]);
    }

    private function createSubscription(User $user, string $priceId): void
    {
        $user->subscriptions()->create([
            'type' => config('subscriptions.subscription_name', 'default'),
            'stripe_id' => 'sub_test_'.Str::lower(Str::random(20)),
            'stripe_status' => 'active',
            'stripe_price' => $priceId,
            'quantity' => 1,
            'trial_ends_at' => null,
            'ends_at' => null,
        ]);
    }

    private function workbookXmlFromBinary(string $binary): string
    {
        return $this->zipEntryFromBinary($binary, 'xl/workbook.xml');
    }

    private function sheetXmlFromBinary(string $binary, int $sheetIndex): string
    {
        return $this->zipEntryFromBinary($binary, sprintf('xl/worksheets/sheet%d.xml', $sheetIndex));
    }

    private function sharedStringsXmlFromBinary(string $binary): string
    {
        return $this->zipEntryFromBinary($binary, 'xl/sharedStrings.xml');
    }

    private function zipEntryFromBinary(string $binary, string $entry): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'penny-xlsx-test-');
        $this->assertNotFalse($tempFile);
        file_put_contents($tempFile, $binary);

        $zip = new ZipArchive();
        $opened = $zip->open($tempFile);
        $this->assertTrue($opened === true);

        $content = $zip->getFromName($entry);
        $zip->close();
        @unlink($tempFile);

        $this->assertNotFalse($content, "Could not read {$entry} from generated spreadsheet.");
        return (string) $content;
    }
}
