<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A LinkedIn job link harvested from an alert email, kept until it is read by something
 * that can see LinkedIn (a signed-in browser session), never fetched by the app itself.
 *
 * @property string $job_id
 * @property string $url
 * @property string|null $title
 * @property string|null $company
 * @property string|null $location
 * @property list<string>|null $notes
 * @property string|null $alert
 * @property string|null $message_id
 * @property string $status
 * @property int|null $opportunity_id
 * @property string|null $description
 * @property string|null $apply_url
 * @property Carbon|null $scanned_at
 * @property string|null $scan_error
 */
class LinkedInLink extends Model
{
    public const string NEW = 'new';

    public const string QUEUED = 'queued';

    public const string SCANNING = 'scanning';

    public const string IMPORTED = 'imported';

    public const string FAILED = 'failed';

    public const string DISMISSED = 'dismissed';

    #[\Override]
    protected $guarded = ['id', 'created_at', 'updated_at'];

    #[\Override]
    protected $table = 'linkedin_links';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['notes' => 'array', 'scanned_at' => 'datetime'];
    }

    /**
     * The job id from a canonical LinkedIn link, or null when the link is not one.
     */
    public static function jobIdFrom(string $url): ?string
    {
        return preg_match('#^https://www\.linkedin\.com/jobs/view/(\d+)$#', $url, $match) === 1 ? $match[1] : null;
    }
}
