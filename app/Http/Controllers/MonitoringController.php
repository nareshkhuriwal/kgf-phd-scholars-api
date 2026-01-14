<?php
// app/Http/Controllers/MonitoringController.php
namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MonitoringController extends Controller
{
    /**
     * Analytics endpoint - SUPERUSER ONLY
     * Returns system-wide analytics including user counts, payment data, and revenue metrics
     */
    public function analytics(Request $req)
    {
        $user = $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Monitoring analytics called', [
            'user_id' => $user->id,
            'role' => $user->role
        ]);

        // Only superuser can access monitoring analytics
        if ($user->role !== 'superuser') {
            Log::warning('Unauthorized monitoring access attempt', [
                'user_id' => $user->id,
                'role' => $user->role,
                'attempted_action' => 'analytics'
            ]);
            abort(403, 'Access denied. Only superusers can access monitoring analytics.');
        }

        Log::info('Superuser authorized to view analytics', [
            'user_id' => $user->id
        ]);

        // time window for payments_over_time: last 30 days by default
        $days = (int) $req->get('days', 30);
        $from = Carbon::now()->subDays($days)->startOfDay();

        Log::debug('Analytics time window', [
            'days' => $days,
            'from' => $from->toDateTimeString()
        ]);

        // user counts by role
        $total_users = User::count();
        $total_admins = User::where('role', 'admin')->count();
        $total_supervisors = User::where('role', 'supervisor')->count();
        $total_researchers = User::where('role', 'researcher')->count();
        $total_superusers = User::where('role', 'superuser')->count();

        Log::info('User counts retrieved', [
            'total' => $total_users,
            'admins' => $total_admins,
            'supervisors' => $total_supervisors,
            'researchers' => $total_researchers,
            'superusers' => $total_superusers
        ]);

        // payments counts and sums
        $total_payments_count = Payment::count();
        $total_payments_amount = (int) Payment::sum('amount'); // amount in paise
        $created_payments_count = Payment::count(); // same as total (or adapt if you have different states)
        $successful_payments_count = Payment::where('status', 'paid')->count();
        // sum only paid payments
        $total_paid_amount = (int) Payment::where('status', 'paid')->sum('amount'); // paise
        $total_paid_amount_inr = number_format($total_paid_amount / 100, 2, '.', '');

        Log::info('Payment metrics calculated', [
            'total_count' => $total_payments_count,
            'successful_count' => $successful_payments_count,
            'total_paid_inr' => $total_paid_amount_inr
        ]);

        // breakdown by status
        $payment_status_breakdown = Payment::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(fn($r) => ['status' => $r->status, 'count' => (int)$r->count]);

        Log::debug('Payment status breakdown', [
            'breakdown' => $payment_status_breakdown->toArray()
        ]);

        // payments over time (daily)
        $payments_over_time = Payment::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(amount) as amount')
            )
            ->where('created_at', '>=', $from)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get()
            ->map(fn($r) => ['date' => $r->date, 'count' => (int)$r->count, 'amount' => (int)$r->amount]);

        Log::debug('Payments over time calculated', [
            'data_points' => $payments_over_time->count()
        ]);

        // top plans by revenue
        $plans_by_revenue = Payment::select('plan_key as plan', DB::raw('SUM(amount) as amount'))
            ->groupBy('plan_key')
            ->orderByDesc('amount')
            ->limit(6)
            ->get()
            ->map(fn($r) => ['plan' => $r->plan, 'amount' => (int)$r->amount]);

        Log::debug('Top plans by revenue', [
            'plan_count' => $plans_by_revenue->count()
        ]);

        // recent payments
        $recent_payments = Payment::with('user:id,name,email')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'user' => $p->user ? ['id'=>$p->user->id, 'name'=>$p->user->name, 'email'=>$p->user->email] : null,
                'user_email' => $p->user?->email,
                'plan_key' => $p->plan_key,
                'meta' => $p->meta,
                'amount' => (int)$p->amount,
                'status' => $p->status,
                'created_at' => $p->created_at,
            ]);

        Log::info('Analytics data compiled successfully', [
            'user_id' => $user->id,
            'recent_payments_count' => $recent_payments->count()
        ]);

        return response()->json([
            'total_users' => $total_users,
            'total_admins' => $total_admins,
            'total_supervisors' => $total_supervisors,
            'total_researchers' => $total_researchers,
            'total_superusers' => $total_superusers,
            'total_payments_count' => $total_payments_count,
            'total_payments_amount' => $total_payments_amount,
            'total_payments_amount_inr'  => number_format($total_payments_amount / 100, 2, '.', ''), // all payments in INR
            'created_payments_count' => $created_payments_count,
            'successful_payments_count' => $successful_payments_count,
            'payment_status_breakdown' => $payment_status_breakdown,
            'payments_over_time' => $payments_over_time,
            'plans_by_revenue' => $plans_by_revenue,
            'recent_payments' => $recent_payments,
            'total_paid_amount'          => $total_paid_amount,                 // paise
            'total_paid_amount_inr'      => $total_paid_amount_inr,             // INR string
        ]);
    }
}