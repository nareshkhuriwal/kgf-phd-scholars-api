<?php
// app/Mail/SupervisorWelcomeMail.php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SupervisorWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public string $plainPassword;
    public string $loginUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, string $plainPassword)
    {
        $this->user = $user;
        $this->plainPassword = $plainPassword;

        // Prefer a dedicated FRONTEND_URL if you have it, else fallback
        $frontend = config('app.frontend_url') ?? env('FRONTEND_URL');
        $this->loginUrl = rtrim($frontend ?: config('app.url'), '/') . '/login';
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $appName = config('app.name', 'KGF Scholars');

        return $this
            ->subject("Welcome to {$appName} – Supervisor Access")
            ->view('emails.supervisors.welcome')
            ->with([
                'appName'       => $appName,
                'user'          => $this->user,
                'plainPassword' => $this->plainPassword,
                'loginUrl'      => $this->loginUrl,
            ]);
    }
}
