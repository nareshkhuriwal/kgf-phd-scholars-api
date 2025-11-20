<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait SupervisesResearchers
{
    /**
     * Return all user IDs whose data the current user is allowed to see:
     *  - themselves
     *  - any researcher who has ACCEPTED this user's invite.
     */
    protected function visibleUserIdsForCurrent(Request $request): array
    {
        $user = $request->user();

        if (!$user) {
            return [];
        }

        $uid = $user->id;

        $researcherIds = DB::table('researcher_invites')
            ->join('users', 'users.email', '=', 'researcher_invites.researcher_email')
            ->where('researcher_invites.created_by', $uid)   // supervisor
            ->where('researcher_invites.status', 'accepted')
            ->pluck('users.id')
            ->all();

        // include self + unique researcher IDs
        return array_values(array_unique(array_merge([$uid], $researcherIds)));
    }
}
