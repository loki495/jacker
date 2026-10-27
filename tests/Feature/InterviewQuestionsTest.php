<?php

declare(strict_types=1);

use App\Actions\MoveInterviewQuestion;
use App\Actions\SaveInterviewQuestion;
use App\Actions\SaveQuestionAnswer;
use App\Models\InterviewQuestion;
use App\Models\Opportunity;
use App\Models\QuestionAnswer;
use Database\Seeders\InterviewQuestionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function question(string $stage = 'screening', string $text = 'Who is the employer?', ?array $options = null): InterviewQuestion
{
    return InterviewQuestion::query()->create(['stage' => $stage, 'question' => $text, 'options' => $options, 'position' => 1]);
}

it('saves questions with one option per line and appends to the end of the stage', function (): void {
    $first = app(SaveInterviewQuestion::class)->handle(['stage' => 'screening', 'question' => ' Remote? ', 'options' => "Yes\n\n No \nYes"]);
    $second = app(SaveInterviewQuestion::class)->handle(['stage' => 'screening', 'question' => 'Salary?']);
    expect($first->question)->toBe('Remote?')->and($first->options)->toBe(['Yes', 'No'])->and($first->position)->toBe(1)->and($second->options)->toBeNull()->and($second->position)->toBe(2);
    app(SaveInterviewQuestion::class)->handle(['stage' => 'interviewing', 'question' => 'Remote?', 'options' => ''], $first);
    expect($first->refresh()->stage->value)->toBe('interviewing')->and($first->options)->toBeNull();
});

it('rejects unknown stages and blank questions', function (): void {
    expect(fn () => app(SaveInterviewQuestion::class)->handle(['stage' => 'saved', 'question' => 'x']))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveInterviewQuestion::class)->handle(['stage' => 'offer', 'question' => ' ']))->toThrow(ValidationException::class);
});

it('stores, updates and clears answers; blank means no row', function (): void {
    $job = Opportunity::factory()->create();
    $q = question();
    app(SaveQuestionAnswer::class)->handle($job, $q, ' Acme ');
    app(SaveQuestionAnswer::class)->handle($job, $q, 'Acme Inc');
    expect(QuestionAnswer::query()->sole()->answer)->toBe('Acme Inc');
    app(SaveQuestionAnswer::class)->handle($job, $q, '  ');
    expect(QuestionAnswer::query()->count())->toBe(0);
});

it('moves a question within its stage only', function (): void {
    $a = question('screening', 'A');
    $b = InterviewQuestion::query()->create(['stage' => 'screening', 'question' => 'B', 'position' => 2]);
    $other = InterviewQuestion::query()->create(['stage' => 'offer', 'question' => 'C', 'position' => 1]);
    app(MoveInterviewQuestion::class)->handle($b, -1);
    app(MoveInterviewQuestion::class)->handle($b, -1);
    expect($b->refresh()->position)->toBe(1)->and($a->refresh()->position)->toBe(2)->and($other->refresh()->position)->toBe(1);
});

it('manages questions on the questions page', function (): void {
    $this->get('/questions')->assertOk()->assertSee('Questions to ask');
    $page = Livewire::test('pages::questions')->set('form.stage', 'offer')->set('form.question', 'How much PTO?')->call('save')->assertHasNoErrors();
    $q = InterviewQuestion::query()->sole();
    expect($q->stage->value)->toBe('offer');
    $page->call('edit', $q->id)->set('form.question', 'How much PTO is offered?')->call('save');
    expect($q->refresh()->question)->toBe('How much PTO is offered?');
    $page->set('form.question', '')->call('save')->assertHasErrors(['form.question']);
    $page->call('delete', $q->id);
    expect(InterviewQuestion::query()->count())->toBe(0);
});

it('answers per job, defaults to the job stage and keeps jobs separate', function (): void {
    $screening = question('screening', 'Who is the employer?');
    question('offer', 'How much PTO?');
    $job = Opportunity::factory()->create(['status' => 'screening']);
    $other = Opportunity::factory()->create();
    $this->get('/opportunities/'.$job->id.'/questions')->assertOk()->assertSee('Who is the employer?')->assertDontSee('How much PTO?');
    Livewire::test('pages::answers', ['opportunity' => $job])->assertSet('stage', 'screening')->set('answers.'.$screening->id, 'Acme');
    expect(QuestionAnswer::query()->where('opportunity_id', $job->id)->sole()->answer)->toBe('Acme')->and(QuestionAnswer::query()->where('opportunity_id', $other->id)->count())->toBe(0);
    Livewire::test('pages::answers', ['opportunity' => $job])->assertSet('answers.'.$screening->id, 'Acme')->set('stage', '')->assertSee('How much PTO?');
});

it('offers predefined options and keeps an answer that left the list', function (): void {
    $q = question('screening', 'Contract type?', ['Direct hire', 'Contract']);
    $job = Opportunity::factory()->create();
    QuestionAnswer::query()->create(['opportunity_id' => $job->id, 'interview_question_id' => $q->id, 'answer' => 'Temp']);
    Livewire::test('pages::answers', ['opportunity' => $job, 'stage' => 'screening'])->assertSee('Direct hire')->assertSee('Temp (no longer in the list)');
});

it('seeds the question bank once', function (): void {
    $this->seed(InterviewQuestionSeeder::class);
    $count = InterviewQuestion::query()->count();
    $this->seed(InterviewQuestionSeeder::class);
    expect($count)->toBe(79)->and(InterviewQuestion::query()->count())->toBe($count)->and(InterviewQuestion::query()->where('stage', 'screening')->count())->toBe(24);
});

it('deletes answers with their question and their job', function (): void {
    $job = Opportunity::factory()->create();
    $q = question();
    app(SaveQuestionAnswer::class)->handle($job, $q, 'x');
    $q->delete();
    expect(QuestionAnswer::query()->count())->toBe(0);
});

it('opens the recruiter questions for a job that is only in recruiter contact', function (): void {
    $screening = question('screening', 'Who is the employer?');
    question('offer', 'How much PTO?');
    $job = Opportunity::factory()->create(['company' => 'Unknown (Jane at Acme Staffing)', 'status' => 'contacted']);
    Livewire::test('pages::answers', ['opportunity' => $job])->assertSet('stage', 'screening')->assertSee('Who is the employer?')->assertDontSee('How much PTO?')->set('answers.'.$screening->id, 'Acme Staffing client');
    expect(QuestionAnswer::query()->where('opportunity_id', $job->id)->sole()->answer)->toBe('Acme Staffing client');
});
