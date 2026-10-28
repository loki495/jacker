<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AiDriver;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Services\AiSdk;
use App\Support\AiSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Exceptions\AiException;
use Throwable;

final readonly class RunAiRequest
{
    public function __construct(private AiSdk $sdk) {}

    public function handle(int $id): void
    {
        $request = AiRequest::query()->find($id);
        if (! $request instanceof AiRequest || $request->driver !== AiDriver::Sdk || $request->status !== LinkCheckStatus::Queued) {
            return;
        }
        try {
            $claim = match ($request->kind) {
                AiRequest::KIND_EXTRACT => app(ClaimAiRequest::class)->handle($id, AiDriver::Sdk),
                AiRequest::KIND_SCORE => app(ClaimScoreRequest::class)->handle($id, AiDriver::Sdk),
                AiRequest::KIND_LETTER => app(ClaimLetterRequest::class)->handle($id, AiDriver::Sdk),
                default => null,
            };
            if ($claim === null) {
                return;
            }
            $request->refresh();
            if (! AiSettings::validProviderModel($request->agent, $request->model)) {
                $this->fail($request, __('ai.unusable'));

                return;
            }
            if (blank(config('ai.providers.'.$request->agent.'.key'))) {
                $this->fail($request, __('ai.unconfigured'));

                return;
            }
            $response = $this->sdk->prompt($claim['prompt'], (string) $request->agent, (string) $request->model);
            DB::transaction(function () use ($request, $response): void {
                $request->update(['agent' => $response->meta->provider ?? $request->agent, 'model' => $response->meta->model ?? $request->model]);
                match ($request->kind) {
                    AiRequest::KIND_EXTRACT => app(CompleteAiRequest::class)->handle($request, $response->text),
                    AiRequest::KIND_SCORE => app(CompleteScoreRequest::class)->handle($request, $response->text),
                    AiRequest::KIND_LETTER => app(CompleteLetterRequest::class)->handle($request, $response->text),
                    default => null,
                };
            });
        } catch (AiException|ConnectionException|RequestException) {
            $this->fail($request, __('ai.provider_failed'));
        } catch (Throwable $exception) {
            $this->fail($request, __('ai.unexpected'));
            report($exception);
            if (config('app.debug') || app()->environment('local')) {
                throw $exception;
            }
        }
    }

    private function fail(AiRequest $request, string $message): void
    {
        $request->update(['status' => LinkCheckStatus::Failed, 'message' => $message, 'finished_at' => now()]);
    }
}
