<?php

return [
    'paths' => [
        resource_path('views'),
    ],

    // Do not wrap this path in realpath(). On a fresh source extract the directory
    // may not exist until Composer's pre-autoload hook creates Laravel runtime dirs.
    // Returning the intended path keeps view:clear / view:cache / optimize:clear valid.
    'compiled' => env('VIEW_COMPILED_PATH', storage_path('framework/views')),
];
