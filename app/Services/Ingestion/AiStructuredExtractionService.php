<?php

namespace App\Services\Ingestion;

use OpenAI\Laravel\Facades\OpenAI;

class AiStructuredExtractionService
{
    /**
     * @return array{transactions: array<int, array<string,mixed>>, attempts: int}
     */
    public function extractStatementTransactions(string $rawText): array
    {
        $prompt = <<<PROMPT
Extract bank transactions from the provided statement text.
Return valid JSON only with this exact shape:
{
  "transactions": [
    {
      "date": "YYYY-MM-DD",
      "description": "string",
      "amount": number,
      "type": "debit|credit"
    }
  ]
}
Rules:
- Ignore headers and table labels.
- Ignore beginning/ending balance rows and summary rows.
- Ignore totals and non-transaction lines.
- Date must be ISO 8601 format YYYY-MM-DD.
- Amount must be numeric only.
- Do not add markdown fences.
PROMPT;

        $result = $this->callJsonPrompt($prompt, $rawText, 1200, 2);

        $transactions = $result['transactions'] ?? null;
        if (! is_array($transactions)) {
            throw new \RuntimeException('AI statement extraction returned invalid JSON schema.');
        }

        return [
            'transactions' => $transactions,
            'attempts' => $result['_attempts'] ?? 1,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function interpretReceipt(string $ocrText): array
    {
        $prompt = <<<PROMPT
Interpret a receipt OCR text and return valid JSON only in this format:
{
  "merchant": "string|null",
  "date": "YYYY-MM-DD|null",
  "total": number|null,
  "tax": number|null,
  "line_items": [
    {
      "name": "string",
      "amount": number
    }
  ]
}
Rules:
- Most likely total is the largest bottom number labeled total.
- Ignore phone numbers and store IDs.
- Extract date from top area when possible.
- Normalize currency values to plain numbers.
- If uncertain, return null for that field.
- Do not add markdown fences.
PROMPT;

        return $this->callJsonPrompt($prompt, $ocrText, 1200, 2);
    }

    /**
     * @return array{suggested_category:string, confidence:float}|null
     */
    public function suggestCategory(string $description, array $customCategories = []): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $categories = array_values(array_unique(array_filter(array_merge([
            'Needs',
            'Wants',
            'Future',
            'Groceries',
            'Dining',
            'Transportation',
            'Housing',
            'School',
            'Shopping',
            'Subscriptions',
            'Misc',
            'Income',
        ], $customCategories))));

        $prompt = "Classify this transaction description into one category from this list only: "
            .implode(', ', $categories)
            .". Return valid JSON only: {\"suggested_category\":\"...\",\"confidence\":0-1}."
            ." If uncertain, choose \"Misc\" with lower confidence.";

        try {
            $result = $this->callJsonPrompt($prompt, $description, 150, 1);
        } catch (\Throwable) {
            return null;
        }

        $category = trim((string) ($result['suggested_category'] ?? ''));
        $confidence = (float) ($result['confidence'] ?? 0.0);

        if ($category === '' || ! in_array($category, $categories, true)) {
            return null;
        }

        return [
            'suggested_category' => $category,
            'confidence' => max(0.0, min(1.0, $confidence)),
        ];
    }

    public function isEnabled(): bool
    {
        return trim((string) config('services.openai.key', '')) !== '';
    }

    /**
     * @return array<string,mixed>
     */
    private function callJsonPrompt(string $instruction, string $input, int $maxTokens, int $retries): array
    {
        if (! $this->isEnabled()) {
            throw new \RuntimeException('OpenAI is not configured.');
        }

        config([
            'openai.api_key' => (string) config('services.openai.key', ''),
            'openai.request_timeout' => max(15, (int) config('services.openai.timeout', 60)),
        ]);

        $model = (string) config('services.openai.model', 'gpt-4o-mini');
        $attempts = 0;
        $lastError = null;

        for ($i = 0; $i < $retries; $i++) {
            $attempts += 1;
            try {
                $response = OpenAI::chat()->create([
                    'model' => $model,
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.1,
                    'max_tokens' => $maxTokens,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a strict financial data extraction engine. Output JSON only.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $instruction."\n\nINPUT:\n".$input,
                        ],
                    ],
                ]);

                $content = $response->choices[0]->message->content ?? '';
                if (is_array($content)) {
                    $parts = [];
                    foreach ($content as $item) {
                        $segment = (string) ($item['text'] ?? ($item['content'] ?? ''));
                        if ($segment !== '') {
                            $parts[] = $segment;
                        }
                    }
                    $content = implode("\n", $parts);
                }

                $decoded = json_decode(trim((string) $content), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($decoded)) {
                    throw new \RuntimeException('JSON response is not an object.');
                }

                $decoded['_attempts'] = $attempts;

                return $decoded;
            } catch (\Throwable $error) {
                $lastError = $error;
            }
        }

        throw new \RuntimeException('AI extraction failed: '.($lastError?->getMessage() ?? 'unknown error'));
    }
}
