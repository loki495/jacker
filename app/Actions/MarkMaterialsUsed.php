<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\CoverLetter;
use App\Models\Opportunity;
use App\Models\Resume;
use App\Services\MarkdownPdf;
use App\Support\AgentFiles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Freezes the exact PDF bytes of the chosen resume and letter versions under apply/{id}/used/{timestamp}/ and records
 * the choice. Later edits or regeneration never touch that copy. Does not change the job's status.
 */
final class MarkMaterialsUsed
{
    public const array RESUME_CHOICES = ['generated', 'edited', 'general'];

    public const array LETTER_CHOICES = ['generated', 'edited'];

    public function handle(Opportunity $opportunity, string $resumeChoice, string $letterChoice): Opportunity
    {
        $resumeChoice = in_array($resumeChoice, self::RESUME_CHOICES, true) ? $resumeChoice : null;
        $letterChoice = in_array($letterChoice, self::LETTER_CHOICES, true) ? $letterChoice : null;
        if ($resumeChoice === null && $letterChoice === null) {
            throw ValidationException::withMessages(['used' => __('materials.used_nothing')]);
        }
        $files = array_filter([
            'resume' => $resumeChoice === null ? null : $this->bytes($opportunity, 'resume', $resumeChoice),
            'letter' => $letterChoice === null ? null : $this->bytes($opportunity, 'letter', $letterChoice),
        ], is_string(...));

        $usedAt = Carbon::now('UTC')->startOfSecond();
        $dir = self::directory($opportunity, $usedAt);
        if (File::exists($dir)) {
            throw ValidationException::withMessages(['used' => __('materials.used_again')]);
        }
        File::ensureDirectoryExists($dir);
        try {
            foreach ($files as $kind => $pdf) {
                file_put_contents($dir.'/'.$kind.'.pdf', $pdf, LOCK_EX);
            }

            return DB::transaction(function () use ($opportunity, $resumeChoice, $letterChoice, $usedAt): Opportunity {
                $opportunity->forceFill(['resume_choice' => $resumeChoice, 'letter_choice' => $letterChoice, 'materials_used_at' => $usedAt])->save();

                return $opportunity->refresh();
            });
        } catch (\Throwable $e) {
            File::deleteDirectory($dir);
            throw $e;
        }
    }

    /** Where the frozen copy recorded at $usedAt lives. */
    public static function directory(Opportunity $opportunity, Carbon $usedAt): string
    {
        return storage_path('app/agent/apply/'.$opportunity->id.'/used/'.$usedAt->copy()->utc()->format('Ymd\THis\Z'));
    }

    private function bytes(Opportunity $opportunity, string $kind, string $version): string
    {
        if ($version === 'edited') {
            $body = $kind === 'resume'
                ? Resume::query()->where('opportunity_id', $opportunity->id)->first()?->body
                : CoverLetter::query()->where('opportunity_id', $opportunity->id)->first()?->body;
            if ($body === null) {
                throw ValidationException::withMessages(['used' => __('materials.used_missing', ['kind' => $kind, 'version' => $version])]);
            }
            try {
                return (new MarkdownPdf)->render($body, $kind)['pdf'];
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['used' => __('materials.render_problem', ['message' => $e->getMessage()])]);
            }
        }
        $path = $version === 'general' ? AgentFiles::generalResume() : AgentFiles::path($opportunity, $kind);
        $pdf = is_string($path) && is_file($path) && ! is_link($path) ? file_get_contents($path) : false;
        if ($pdf === false) {
            throw ValidationException::withMessages(['used' => __('materials.used_missing', ['kind' => $kind, 'version' => $version])]);
        }

        return $pdf;
    }
}
