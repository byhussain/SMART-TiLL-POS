<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Generate Reference On Create
    |--------------------------------------------------------------------------
    |
    | POS keeps "reference" as a server-assigned field. Local creates should not
    | generate references; server sync will assign store-scoped references.
    |
    */
    'reference_on_create' => false,
];
