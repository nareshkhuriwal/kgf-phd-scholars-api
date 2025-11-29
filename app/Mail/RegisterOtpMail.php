<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RegisterOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $otp;

    /**
     * Create a new message instance.
     *
     * Accepts either:
     *   new RegisterOtpMail($userObject, '123456')
     * or
     *   new RegisterOtpMail('123456')
     *
     * This keeps backwards-compatibility and avoids "optional before required"
     * deprecation warnings.
     *
     * @param mixed $first  Either a user object or the otp string
     * @param mixed $second Optional otp when first is user
     */
    public function __construct($first, $second = null)
    {
        // Default initial values
        $this->user = null;
        $this->otp = null;

        // Called as: new RegisterOtpMail($user, $otp)
        if (is_object($first) && is_string($second)) {
            $this->user = $first;
            $this->otp = $second;
            return;
        }

        // Called as: new RegisterOtpMail($otp)
        if (is_string($first) && $second === null) {
            $this->otp = $first;
            $this->user = null;
            return;
        }

        // Fallback: try to coerce whatever was passed
        if (is_string($second)) {
            // maybe caller passed (null, 'otp') or ('', 'otp')
            $this->otp = $second;
        } elseif (is_string($first)) {
            $this->otp = $first;
        } else {
            $this->otp = (string) ($second ?? $first ?? '');
        }

        if (is_object($first) && $this->user === null) {
            $this->user = $first;
        } elseif (is_object($second) && $this->user === null) {
            $this->user = $second;
        }
    }

    public function build()
    {
        return $this->subject('Email verification – KGF Scholars')
                    ->view('emails.register_otp')
                    ->with([
                        'user' => $this->user,
                        'otp'  => $this->otp,
                    ]);
    }
}
