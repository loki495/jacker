<?php

declare(strict_types=1);

namespace App\Enums;

enum EmbeddingKind: string
{
    case Job = 'job';
    case Letter = 'letter';
}
