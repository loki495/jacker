<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\InterviewQuestion;
use Illuminate\Support\Facades\DB;

final class MoveInterviewQuestion
{
    public function handle(InterviewQuestion $question, int $direction): void
    {
        DB::transaction(function () use ($question, $direction): void {
            $ids = InterviewQuestion::query()->where('stage', $question->stage)->orderBy('position')->orderBy('id')->pluck('id')->all();
            $index = array_search($question->id, $ids, true);
            $swap = $index === false ? null : ($index + ($direction < 0 ? -1 : 1));
            if ($swap === null || ! isset($ids[$swap])) {
                return;
            }
            [$ids[$index], $ids[$swap]] = [$ids[$swap], $ids[$index]];
            foreach ($ids as $position => $id) {
                InterviewQuestion::query()->whereKey($id)->update(['position' => $position + 1]);
            }
        });
    }
}
