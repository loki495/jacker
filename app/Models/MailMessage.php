<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Record of a scanned email: identifying headers and link counts only, never the body.
 *
 * @property string $message_id
 * @property int $links_found
 * @property int $links_queued
 */
class MailMessage extends Model
{
    #[\Override]
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
