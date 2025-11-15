<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class PasswordOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public string $otp;

    public function __construct(User $user, string $otp)
    {
        $this->user = $user;
        $this->otp  = $otp;
    }

    public function build()
    {
        return $this
            ->subject('KGF Scholars – Password Reset OTP')
            ->view('emails.password_otp');
    }
}
