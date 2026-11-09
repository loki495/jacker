<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SearchProfile;
use Illuminate\Console\Command;

final class ProfileSet extends Command
{
    #[\Override]
    protected $signature = 'profile:set {--source=settings : Where the text came from, shown next to it (for example dibs:12)}';

    #[\Override]
    protected $description = 'Replace the search profile text with what arrives on standard input (used by bin/profile-sync); empty input clears it.';

    public function handle(): int
    {
        $text = trim((string) stream_get_contents(STDIN));
        $source = (string) $this->option('source');
        if (mb_strlen($text) > (int) config('searchprofile.max_chars') || ! preg_match('/^[a-z0-9:_-]{1,40}$/', $source)) {
            $this->error(__('api.profile_set_invalid', ['max' => config('searchprofile.max_chars')]));

            return self::FAILURE;
        }
        SearchProfile::save($text, $source);
        $this->info($text === '' ? 'cleared' : 'saved '.mb_strlen($text).' characters from '.$source);

        return self::SUCCESS;
    }
}
