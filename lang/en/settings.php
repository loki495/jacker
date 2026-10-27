<?php

declare(strict_types=1);

return [
    'nav' => 'Grading settings',
    'page_title' => 'Grading settings',
    'heading' => 'Grading settings',
    'intro' => 'These criteria drive AI grading. Changes apply to the next grading run; existing grades stay until a listing is graded again.',
    'saved' => 'Settings saved.',
    'reset_done' => 'Settings reset to the defaults.',
    'save' => 'Save settings',
    'reset' => 'Reset to defaults',
    'reset_confirm' => 'Discard your saved criteria and go back to the defaults?',
    'fit_title' => 'Skill match (judged by the AI)',
    'rubric' => 'Rubric',
    'rubric_hint' => 'Describe the candidate and how to score 1 to 5. It must keep asking for eastern_only_hours.',
    'desirability_title' => 'Desirability (computed from pay and work mode)',
    'pay_weight' => 'Pay weight (0 to 1)',
    'pay_weight_hint' => 'Work mode gets the remaining share.',
    'pay_unknown' => 'Points when pay is unknown (1 to 5)',
    'eastern_penalty' => 'Points taken off for Eastern-only hours',
    'pay_bands' => 'Pay bands',
    'pay_bands_hint' => 'One per line as "annual USD midpoint = points". Include a 0 line as the floor.',
    'work_mode' => 'Points per work arrangement (1 to 5)',
    'bands_invalid' => 'Each pay band must look like "120000 = 3", with points from 1 to 5.',
    'bands_floor' => 'Add a band with a floor of 0.',
];
