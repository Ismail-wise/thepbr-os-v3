<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\AI\PbrAiProvider;

final class DisabledPbrAiProvider implements PbrAiProvider
{
    public function available(): bool
    {
        return false;
    }

    public function respond(array $request): string
    {
        $languageMode = (string) (
            $request['context']['language_mode'] ?? 'en'
        );

        return match ($languageMode) {
            'my' => 'PBR AI ကို ဒီ environment မှာ မချိတ်ဆက်ရသေးပါ။',
            'mixed' => 'PBR AI is not configured in this environment · ဒီ environment မှာ မချိတ်ဆက်ရသေးပါ။',
            default => 'PBR AI is not configured in this environment.',
        };
    }
}
