<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Review;

class ReviewPolicy
{
    /**
     * Can the user update this review?
     */
    public function update(User $user, Review $review): bool
    {
        return $review->created_by === $user->id;
    }

    /**
     * Can the user view this review?
     */
    public function view(User $user, Review $review): bool
    {
        return $review->created_by === $user->id;
    }
}
