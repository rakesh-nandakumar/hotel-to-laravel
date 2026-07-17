<?php

use App\Mail\OtpMail;

it('renders the login verification email with the code and expiry', function () {
    $mail = new OtpMail('482913', 'login', 10);

    $mail->assertHasSubject('Your login verification code');
    $mail->assertSeeInHtml('482913');
    $mail->assertSeeInHtml('finish signing in');
    $mail->assertSeeInHtml('10 minutes');
});

it('renders the password reset email with its own subject and wording', function () {
    $mail = new OtpMail('123456', 'forgot_password', 15);

    $mail->assertHasSubject('Your password reset code');
    $mail->assertSeeInHtml('123456');
    $mail->assertSeeInHtml('reset your password');
});
