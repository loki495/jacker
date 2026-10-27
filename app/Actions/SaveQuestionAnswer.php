<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\InterviewQuestion;
use App\Models\Opportunity;
use App\Models\QuestionAnswer;

final class SaveQuestionAnswer
{
    /** A blank answer removes the row: unanswered and not applicable look the same. */
    public function handle(Opportunity $opportunity, InterviewQuestion $question, ?string $answer): void
    {
        $answer = trim((string) $answer);
        $match = ['opportunity_id' => $opportunity->id, 'interview_question_id' => $question->id];
        if ($answer === '') {
            QuestionAnswer::query()->where($match)->delete();

            return;
        }
        QuestionAnswer::query()->updateOrCreate($match, ['answer' => mb_substr($answer, 0, 10000)]);
    }
}
