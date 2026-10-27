<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $opportunity_id
 * @property int $interview_question_id
 * @property string $answer
 */
class QuestionAnswer extends Model
{
    #[\Override]
    protected $guarded = ['id', 'created_at', 'updated_at'];
}
