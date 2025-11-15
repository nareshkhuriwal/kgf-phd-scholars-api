{{-- resources/views/emails/supervisors/welcome.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Welcome to {{ $appName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f5f7;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
<table width="100%" cellspacing="0" cellpadding="0" style="background-color:#f5f5f7;padding:24px 0;">
    <tr>
        <td align="center">
            <table width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background-color:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e5e5ea;">
                <tr>
                    <td style="background:linear-gradient(90deg,#ff9800 0%,#fbc02d 100%);padding:16px 24px;color:#ffffff;">
                        <h1 style="margin:0;font-size:20px;font-weight:700;">
                            {{ $appName }}
                        </h1>
                        <p style="margin:4px 0 0;font-size:14px;opacity:0.9;">
                            Supervisor account created successfully
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px;">
                        <p style="margin:0 0 12px;font-size:14px;color:#111827;">
                            Hi {{ $user->name ?? 'Supervisor' }},
                        </p>
                        <p style="margin:0 0 16px;font-size:14px;color:#4b5563;line-height:1.5;">
                            An administrator has created a supervisor account for you on
                            <strong>{{ $appName }}</strong>.
                        </p>

                        <p style="margin:0 0 8px;font-size:14px;color:#111827;font-weight:600;">
                            Your login details:
                        </p>

                        <table cellspacing="0" cellpadding="0" style="margin:0 0 16px;font-size:14px;color:#111827;">
                            <tr>
                                <td style="padding:2px 0;width:120px;color:#6b7280;">Login URL:</td>
                                <td style="padding:2px 0;">
                                    <a href="{{ $loginUrl }}" style="color:#1d4ed8;text-decoration:none;">
                                        {{ $loginUrl }}
                                    </a>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:2px 0;width:120px;color:#6b7280;">Username:</td>
                                <td style="padding:2px 0;">{{ $user->email }}</td>
                            </tr>
                            <tr>
                                <td style="padding:2px 0;width:120px;color:#6b7280;">Password:</td>
                                <td style="padding:2px 0;"><strong>{{ $plainPassword }}</strong></td>
                            </tr>
                        </table>

                        <p style="margin:0 0 16px;font-size:13px;color:#6b7280;line-height:1.5;">
                            For security reasons, please log in and change your password
                            after your first sign in.
                        </p>

                        <p style="margin:0 0 24px;">
                            <a href="{{ $loginUrl }}"
                               style="display:inline-block;background-color:#ff9800;color:#ffffff;padding:10px 18px;
                                      border-radius:999px;font-size:14px;font-weight:600;text-decoration:none;">
                                Go to Login
                            </a>
                        </p>

                        <p style="margin:0;font-size:12px;color:#9ca3af;line-height:1.5;">
                            If you did not expect this email, you can ignore it.
                            Your account will remain secure.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:12px 24px;border-top:1px solid #e5e7eb;text-align:center;">
                        <p style="margin:0;font-size:11px;color:#9ca3af;">
                            © {{ date('Y') }} {{ $appName }}. All rights reserved.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
