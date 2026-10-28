<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AiDriver;
use App\Models\Setting;
use App\Support\AiSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SaveAiSettings
{
    /** @param array<string, mixed> $features */
    public function handle(array $features): void
    {
        $rules = [];
        foreach (AiSettings::FEATURES as $feature) {
            $rules["aiFeatures.{$feature}.driver"] = ['required', Rule::enum(AiDriver::class)];
            $rules["aiFeatures.{$feature}.provider"] = ['required', Rule::in(AiSettings::PROVIDERS)];
            $rules["aiFeatures.{$feature}.model"] = ['required', 'string', 'max:100', 'regex:'.AiSettings::MODEL_PATTERN];
        }
        $validated = Validator::make(['aiFeatures' => $features], $rules)->validate()['aiFeatures'];
        DB::transaction(function () use ($validated): void {
            foreach (AiSettings::FEATURES as $feature) {
                foreach (['driver', 'provider', 'model'] as $field) {
                    Setting::query()->updateOrCreate(['key' => "ai.{$feature}.{$field}"], ['value' => ['v' => $validated[$feature][$field]]]);
                }
            }
        });
    }
}
