<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserOptionResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Paper;
use App\Models\Review; // if you have a Review model
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /**
     * GET /users
     * Query params:
     *  - search: string (match name/email)
     *  - role: string (optional)
     *  - status: string (optional, e.g. active/inactive) — only if you store this
     *  - sort: string CSV of fields, e.g. "name,-created_at" (minus = desc)
     *  - with_counts: bool (include papers_count, reviews_count)
     *  - per_page: int|all (default 25)
     */
    public function index(Request $req)
    {
      $q = User::query();

      // search
      if ($s = $req->get('search')) {
        $q->where(function ($w) use ($s) {
          $w->where('name', 'like', "%{$s}%")
            ->orWhere('email', 'like', "%{$s}%");
        });
      }

      // role filter (if you have a role column)
      if ($role = $req->get('role')) {
        $q->where('role', $role);
      }

      // status filter (if you have a status column)
      if ($status = $req->get('status')) {
        $q->where('status', $status);
      }

      // optional counts (avoid heavy joins; use subqueries)
      $withCounts = filter_var($req->boolean('with_counts'), FILTER_VALIDATE_BOOLEAN) || $req->get('with_counts') === '1';
      if ($withCounts) {
        // papers_count: papers.created_by == users.id
        if (class_exists(Paper::class)) {
          $q->selectSub(
            Paper::selectRaw('COUNT(*)')->whereColumn('papers.created_by', 'users.id'),
            'papers_count'
          );
        }
        // reviews_count: reviews.reviewed_by == users.id  (change column names to match your schema)
        if (class_exists(Review::class)) {
          $q->selectSub(
            Review::selectRaw('COUNT(*)')->whereColumn('reviews.reviewed_by', 'users.id'),
            'reviews_count'
          );
        }
      }

      // sorting
      $sort = $req->get('sort', 'name');
      foreach (explode(',', $sort) as $token) {
        $token = trim($token);
        if (!$token) continue;
        $dir = Str::startsWith($token, '-') ? 'desc' : 'asc';
        $col = ltrim($token, '-');
        // whitelist common fields
        if (in_array($col, ['id','name','email','role','status','created_at','updated_at','papers_count','reviews_count'], true)) {
          $q->orderBy($col, $dir);
        }
      }

      // paginate or return all
      $per = $req->get('per_page', 25);
      if ($per === 'all') {
        $rows = $q->get();
        return UserResource::collection($rows);
      }

      $p = (int) $per;
      $rows = $q->paginate($p > 0 ? $p : 25);

      return UserResource::collection($rows);
    }

    /**
     * GET /users/{user}
     * Return single user (safe fields). This method is non-breaking addition used by monitoring.
     */
    public function show(User $user)
    {
        // hide sensitive attributes via resource or directly
        // UserResource should handle attribute hiding; here we just return resource
        return new UserResource($user);
    }

    /**
     * GET /reports/users  (lightweight options for dropdowns)
     * Optional: search, per_page=all
     */
    public function options(Request $req)
    {
      $q = User::query();

      if ($s = $req->get('search')) {
        $q->where(function ($w) use ($s) {
          $w->where('name', 'like', "%{$s}%")
            ->orWhere('email', 'like', "%{$s}%");
        });
      }

      $per = $req->get('per_page', 50);
      if ($per === 'all') {
        $rows = $q->orderBy('name')->get();
        return UserOptionResource::collection($rows);
      }

      $rows = $q->orderBy('name')->paginate((int)$per ?: 50);
      return UserOptionResource::collection($rows);
    }
}
