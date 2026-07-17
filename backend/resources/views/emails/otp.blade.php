<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Verification code</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; background:#f5f5f5; padding: 24px;">
    <div style="max-width: 480px; margin: 0 auto; background:#fff; border-radius: 8px; padding: 32px;">
        <h1 style="margin: 0 0 12px; font-size: 20px; color: #111;">Your verification code</h1>
        <p style="color: #444; line-height: 1.5;">
            Use the code below to
            @switch($purpose)
                @case('forgot_password') reset your password @break
                @case('login') finish signing in @break
                @default complete your verification
            @endswitch.
            This code expires in {{ $expiresInMinutes }} minutes.
        </p>
        <div style="font-family: 'Courier New', monospace; font-size: 32px; letter-spacing: 8px; font-weight: bold; padding: 16px 0; text-align: center; color: #111;">
            {{ $otp }}
        </div>
        <p style="color: #888; font-size: 12px; line-height: 1.5;">
            If you did not request this, ignore this email. No changes will be made to your account.
        </p>
    </div>
</body>
</html>
