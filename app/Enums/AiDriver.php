<?php

declare(strict_types=1);

namespace App\Enums;

enum AiDriver: string
{
    case Bridge = 'bridge';
    case Sdk = 'sdk';
}
