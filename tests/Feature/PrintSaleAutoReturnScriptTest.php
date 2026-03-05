<?php

it('includes robust auto return hooks after print dialog closes', function (): void {
    $contents = file_get_contents(resource_path('views/print/sale.blade.php'));

    expect($contents)
        ->toContain('scheduleReturn')
        ->toContain("window.addEventListener('focus'")
        ->toContain("document.addEventListener('visibilitychange'")
        ->toContain('window.addEventListener(\'afterprint\'');
});
