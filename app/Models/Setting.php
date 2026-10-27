<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property mixed $value
 */
class Setting extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $primaryKey = 'key';

    #[\Override]
    protected $keyType = 'string';

    #[\Override]
    protected $guarded = ['created_at', 'updated_at'];

    #[\Override]
    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
