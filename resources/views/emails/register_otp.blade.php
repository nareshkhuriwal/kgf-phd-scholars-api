<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Email verification OTP</title>
</head>
<body style="font-family: Arial, sans-serif; background: #f5f5f5; padding: 24px;">
    <div style="max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 24px;">
        <h2 style="margin-top: 0; color: #1e1e1f;">
            Email verification – KGF Scholars
        </h2>

        <p>Hi {{ $user->name ?? 'there' }},</p>

        <p>Thanks for signing up. Use the following one-time password (OTP) to verify your email address:</p>

        <p style="margin: 16px 0;">
            <strong style="font-size:13px;color:#666">One-time password (valid 15 mins)</strong>
        </p>

        <p style="font-size: 24px; letter-spacing: 4px; font-weight: bold; color: #0b7285;">
            {{ $otp }}
        </p>

        <p style="font-size: 13px; color: #666;">
            This OTP is valid for 15 minutes. If you didn’t request this, you can safely ignore this email.
        </p>

        <p style="margin-top: 24px;">
            Regards,<br>
            <strong>KGF Scholars</strong>
        </p>
    </div>
</body>
</html>
