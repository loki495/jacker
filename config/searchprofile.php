<?php

declare(strict_types=1);

return [
    // Local Dibs id of the record bin/profile-sync copies into the profile. Empty: the Settings page owns the text.
    'dibs_id' => ($id = env('SEARCH_PROFILE_DIBS_ID')) === null || $id === '' ? null : (int) $id,
    'max_chars' => 20000,
];
