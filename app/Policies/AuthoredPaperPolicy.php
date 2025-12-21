<?php
// app/Policies/AuthoredPaperPolicy.php

namespace App\Policies;

use App\Models\AuthoredPaper;
use App\Models\User;

class AuthoredPaperPolicy
{
    public function update(User $user, AuthoredPaper $paper): bool
    {
        return $paper->user_id === $user->id;
    }

    public function view(User $user, AuthoredPaper $paper): bool
    {
        return $paper->user_id === $user->id;
    }
}
