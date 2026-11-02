<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;

/** Presence of the host agent worker (bin/agent-worker). The app container cannot see host tools, so the worker reports itself with agent:register. */
final class AgentWorker
{
    private const string KEY = 'agent.worker';

    private const string GENERAL_KEY = 'agent.general_resume';

    /** Seconds without a heartbeat before the worker counts as gone. */
    public const int STALE = 90;

    public static function register(): void
    {
        Setting::query()->updateOrCreate(['key' => self::KEY], ['value' => ['v' => now()->timestamp]]);
    }

    public static function online(): bool
    {
        $seen = Setting::query()->find(self::KEY)?->value['v'] ?? null;

        return is_int($seen) && now()->timestamp - $seen <= self::STALE;
    }

    /** The worker copies the general resume PDF into storage on every loop and reports whether its source exists. */
    public static function setGeneralResume(bool $found): void
    {
        Setting::query()->updateOrCreate(['key' => self::GENERAL_KEY], ['value' => ['v' => $found]]);
    }

    /** True when the last sync found no source file; false when it found one or the worker has not reported. */
    public static function generalResumeMissing(): bool
    {
        return Setting::query()->find(self::GENERAL_KEY)?->value['v'] === false;
    }
}
