<?php

it('has no root web page — this is an API-only app', function () {
    $this->get('/')->assertNotFound();
});
