<?php

namespace App\Services\Ingestion;

class CategorySuggestionService
{
    /** @var array<string, array{category:?string, confidence:float, framework:?string}> */
    private array $cache = [];

    public function __construct(private readonly AiStructuredExtractionService $aiExtractor)
    {
    }

    /**
     * @param array<int, string> $customCategories
     * @return array{category:?string, confidence:float, framework:?string}
     */
    public function suggest(string $description, array $customCategories = []): array
    {
        $key = strtolower(trim($description)).'|'.strtolower(implode('|', $customCategories));
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $heuristic = $this->heuristicSuggestion($description);

        $aiSuggestion = $this->aiExtractor->suggestCategory($description, $customCategories);
        if ($aiSuggestion !== null) {
            $framework = $aiSuggestion['suggested_category'];
            $mapped = $this->mapToAppCategory($framework);
            $result = [
                'category' => $aiSuggestion['confidence'] >= 0.55 ? $mapped : null,
                'confidence' => $aiSuggestion['confidence'],
                'framework' => $framework,
            ];

            return $this->cache[$key] = $result;
        }

        return $this->cache[$key] = $heuristic;
    }

    /**
     * @return array{category:?string, confidence:float, framework:?string}
     */
    private function heuristicSuggestion(string $description): array
    {
        $text = strtoupper($description);

        if ($this->containsAny($text, ['PAYROLL', 'DEPOSIT', 'ACH CREDIT', 'DIRECT DEP'])) {
            return ['category' => 'Income', 'confidence' => 0.82, 'framework' => 'Needs'];
        }

        if ($this->containsAny($text, ['TRADER JOE', 'WHOLE FOODS', 'SAFEWAY', 'GROCERY', 'WALMART'])) {
            return ['category' => 'Groceries', 'confidence' => 0.78, 'framework' => 'Needs'];
        }

        if ($this->containsAny($text, ['UBER', 'LYFT', 'SHELL', 'EXXON', 'CHEVRON', 'GAS'])) {
            return ['category' => 'Transportation', 'confidence' => 0.72, 'framework' => 'Needs'];
        }

        if ($this->containsAny($text, ['RENT', 'MORTGAGE', 'APARTMENT', 'LANDLORD'])) {
            return ['category' => 'Housing', 'confidence' => 0.86, 'framework' => 'Needs'];
        }

        if ($this->containsAny($text, ['NETFLIX', 'SPOTIFY', 'SUBSCRIPTION', 'APPLE.COM/BILL'])) {
            return ['category' => 'Subscriptions', 'confidence' => 0.73, 'framework' => 'Needs'];
        }

        if ($this->containsAny($text, ['RESTAURANT', 'COFFEE', 'CAFE', 'DOORDASH', 'GRUBHUB'])) {
            return ['category' => 'Dining', 'confidence' => 0.70, 'framework' => 'Wants'];
        }

        return ['category' => null, 'confidence' => 0.35, 'framework' => null];
    }

    private function mapToAppCategory(string $value): string
    {
        return match ($value) {
            'Needs' => 'Groceries',
            'Wants' => 'Misc',
            'Future' => 'Misc',
            default => in_array($value, [
                'Groceries',
                'Dining',
                'Transportation',
                'Housing',
                'School',
                'Shopping',
                'Subscriptions',
                'Misc',
                'Income',
            ], true) ? $value : 'Misc',
        };
    }

    /**
     * @param array<int, string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
