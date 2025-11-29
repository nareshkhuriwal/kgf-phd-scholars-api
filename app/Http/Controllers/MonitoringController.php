<?php
// app/Http/Controllers/MonitoringController.php
namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MonitoringController extends Controller
{
    public function analytics(Request $req)
    {
        // time window for payments_over_time: last 30 days by default
        $days = (int) $req->get('days', 30);
        $from = Carbon::now()->subDays($days)->startOfDay();

        // user counts by role
        $total_users = User::count();
        $total_admins = User::where('role', 'admin')->count();
        $total_supervisors = User::where('role', 'supervisor')->count();
        $total_researchers = User::where('role', 'researcher')->count();
        $total_super_admins = User::where('role', 'super_admin')->count();

        // payments counts and sums
        $total_payments_count = Payment::count();
        $total_payments_amount = (int) Payment::sum('amount'); // amount in paise
        $created_payments_count = Payment::count(); // same as total (or adapt if you have different states)
        $successful_payments_count = Payment::where('status', 'paid')->count();
        // sum only paid payments
        $total_paid_amount = (int) Payment::where('status', 'paid')->sum('amount'); // paise
        $total_paid_amount_inr = number_format($total_paid_amount / 100, 2, '.', '');


        // breakdown by status
        $payment_status_breakdown = Payment::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(fn($r) => ['status' => $r->status, 'count' => (int)$r->count]);

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

        // top plans by revenue
        $plans_by_revenue = Payment::select('plan_key as plan', DB::raw('SUM(amount) as amount'))
            ->groupBy('plan_key')
            ->orderByDesc('amount')
            ->limit(6)
            ->get()
            ->map(fn($r) => ['plan' => $r->plan, 'amount' => (int)$r->amount]);

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

        return response()->json([
            'total_users' => $total_users,
            'total_admins' => $total_admins,
            'total_supervisors' => $total_supervisors,
            'total_researchers' => $total_researchers,
            'total_super_admins' => $total_super_admins,
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
