<?php

test('the application redirects the root path to the startup screen', function () {
    // `/` was a closure returning to_route('startup.index'). It is now a
    // declarative Route::redirect (so the route table can be cached), which
    // returns 302 instead of letting the controller render 200.
    $response = $this->get('/');

    $response->assertRedirect('/startup');
});
