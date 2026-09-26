<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Business entity rollout
    |--------------------------------------------------------------------------
    |
    | Controls whether reads resolve ownership through businesses.business_id
    | or through the legacy users.user_id column.
    |
    | Writes are unaffected: the application dual-writes BOTH columns
    | regardless of this flag, so the data stays consistent either way and the
    | switch can be flipped back without a migration.
    |
    | Rollout: deploy with this false, confirm dual-write is populating
    | business_id, flip to true, compare responses, and only then plan the
    | contract step that makes business_id NOT NULL.
    |
    */

    'use_business_entity' => env('THRYFT_USE_BUSINESS_ENTITY', false),

];
