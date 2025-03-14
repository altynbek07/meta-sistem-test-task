<?php

it('returns a successful response', function () {
    $response = $this->get('/upload');

    $response->assertStatus(200);
});
