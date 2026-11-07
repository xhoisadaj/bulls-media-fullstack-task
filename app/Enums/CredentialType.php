<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CredentialType: string implements HasLabel
{
    case OAuth = 'oauth';
    case ApiKey = 'api_key';
    case Demo = 'demo';

    public function getLabel(): string
    {
        return match ($this) {
            self::OAuth => 'OAuth',
            self::ApiKey => 'API key (fallback)',
            self::Demo => 'Demo data',
        };
    }
}
