<?php

// Self-registration is intentionally disabled — accounts are provisioned by
// administrators through user management. These tests pin that decision.

test('the registration screen is not available', function () {
    $this->get('/register')->assertNotFound();
});

test('registration submissions are not accepted', function () {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
});
