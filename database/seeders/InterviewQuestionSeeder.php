<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\InterviewQuestion;
use Illuminate\Database\Seeder;

/** Idempotent: run manually (`php artisan db:seed --class=InterviewQuestionSeeder`); re-running only adds missing questions. */
class InterviewQuestionSeeder extends Seeder
{
    /** @var array<string, list<array{0: string, 1?: list<string>}>> stage => [question, options?] */
    private const array QUESTIONS = [
        'screening' => [
            ['Who is the employer?'],
            ['Is this a direct hire, contract, or contract-to-hire role?', ['Direct hire', 'Contract', 'Contract-to-hire']],
            ['Is it full-time W-2 employment?', ['Yes', 'No']],
            ['What is the approved base salary range?'],
            ['Is there a bonus, equity, or other variable compensation?'],
            ['Is the role fully remote for someone based in California?', ['Yes', 'No', 'Hybrid']],
            ['If hybrid, how many days onsite and at which location?'],
            ['What benefits are offered?'],
            ['Can you share the full job description?'],
            ['Why is the role open?'],
            ['Is this a new position or a replacement?', ['New position', 'Replacement']],
            ['What are the top two or three must-have qualifications?'],
            ['Which listed skills are preferred rather than required?'],
            ['Is Laravel central to the role? Which other technologies are used day to day?'],
            ['What will the person work on first?'],
            ['What level is the role, and who does it report to?'],
            ['How large is the engineering team?'],
            ['What is the interview process?'],
            ['How many interview stages are there?'],
            ['Will there be a coding test, take-home assignment, or live coding?'],
            ['What is the expected hiring timeline?'],
            ['Where is the employer in the process: just starting, interviewing, or nearing a decision?'],
            ['What other information do you need from me at this stage?'],
            ['Who will I be speaking with and what is their role? Will you coordinate scheduling and follow up, or should I contact them directly?'],
        ],
        'interviewing' => [
            ['What does the application or product do, and who uses it?'],
            ['What would I work on during the first three to six months?'],
            ['How much of the role is new development versus maintenance or modernization?'],
            ['What does “modernizing legacy PHP” mean for this codebase?'],
            ['Which PHP and Laravel versions are in use?'],
            ['Is the application built with Laravel, or with another PHP framework?'],
            ['Which frontend technologies are used in production?'],
            ['How much React, TypeScript, Python, or Hack would I use in the role?'],
            ['Are those technologies required from day one, or could someone ramp up?'],
            ['What are the main services, integrations, and APIs I’d work with?'],
            ['Which databases are used, and what are the main data or scaling challenges?'],
            ['What does the deployment and release process look like?'],
            ['What CI/CD tools and practices does the team use?'],
            ['What is the current automated test coverage like?'],
            ['How are code reviews handled?'],
            ['How do the team’s developers share design and architecture decisions?'],
            ['How are technical debt and refactoring prioritized?'],
            ['What are the biggest technical challenges the team is facing?'],
            ['How do you monitor application performance and production errors?'],
            ['How often do engineers handle incidents or production support?'],
            ['Is there an on-call rotation? How often, and what is expected?'],
            ['Are after-hours releases or emergency fixes common?'],
            ['What AI coding tools, if any, does the company provide or approve?'],
            ['What are the rules for using AI tools with company code and data?'],
            ['What does a strong technical contribution look like in this role?'],
            ['What would you want the person hired to have accomplished after 90 days?'],
            ['How do you evaluate the quality and impact of an engineer’s work?'],
            ['What would a typical assignment for this position look like? Who would I answer to?'],
        ],
        'offer' => [
            ['What are the health, dental, and vision plans, and what does employee coverage cost?'],
            ['Is there a 401(k) match? When does eligibility begin?'],
            ['How much PTO is offered?'],
            ['How are holidays and sick leave handled?'],
            ['Are there bonuses, equity, learning funds, or other benefits?'],
            ['Is equipment provided for remote employees?', ['Yes', 'No']],
            ['Is there a home-office or internet stipend?', ['Yes', 'No']],
            ['What are the expected working hours and time zone?'],
            ['Are there core hours when everyone needs to be available?'],
            ['How flexible are start times, breaks, and work schedules?'],
            ['How is workload assigned and prioritized?'],
            ['How are priorities adjusted when there is more work than time?'],
            ['Is performance primarily measured by outcomes and delivery, or by hours and online availability?'],
            ['Does the company track hours, mouse or keyboard activity, screenshots, or other computer activity?', ['Yes', 'No']],
            ['What meetings are expected each week?'],
            ['How often does the team have standups?'],
            ['How does the manager give feedback and set priorities?'],
            ['How often do employees meet with their manager one-on-one?'],
            ['How would you describe the team’s working atmosphere?'],
            ['Can you give an example of how the team handled a disagreement or a difficult deadline?'],
            ['How are new employees onboarded?'],
            ['What does success look like after 90 days and after six months?'],
            ['What opportunities are there to grow technically or take on more responsibility?'],
            ['What are the most common reasons someone struggles in this role?'],
            ['How long have the team’s current members been there?'],
            ['What is the company’s approach to promotions and compensation reviews?'],
            ['What is the expected start date?'],
        ],
    ];

    public function run(): void
    {
        foreach (self::QUESTIONS as $stage => $questions) {
            foreach ($questions as $index => $row) {
                InterviewQuestion::query()->firstOrCreate(['stage' => $stage, 'question' => $row[0]], ['options' => $row[1] ?? null, 'position' => $index + 1]);
            }
        }
    }
}
