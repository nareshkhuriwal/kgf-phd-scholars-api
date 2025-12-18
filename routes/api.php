<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController; // your existing controller
use App\Http\Controllers\PaperController;
use App\Http\Controllers\PaperFileController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\ChapterController;
use App\Http\Controllers\ROLController;
use App\Http\Controllers\LibraryImportController;
use App\Http\Controllers\Reviews\ReviewQueueController;
use App\Http\Controllers\Reviews\ReviewController;
use App\Http\Controllers\PaperCommentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SavedReportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PdfHighlightController;
use App\Http\Controllers\ResearcherInviteController;
use App\Http\Controllers\SupervisorController;
use App\Http\Controllers\PricingController;   // ✅ NEW
use App\Http\Controllers\PaymentController;

use App\Http\Controllers\EditorUploadController;
use App\Http\Controllers\MonitoringController;


// Public or rate-limited auth endpoints
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);   // public
Route::post('/auth/send-register-otp', [AuthController::class, 'sendRegisterOtp']);
Route::post('/auth/verify-register-otp', [AuthController::class, 'verifyRegisterOtp']);

// Forgot password via OTP
Route::post('forgot-password/otp', [AuthController::class, 'sendPasswordOtp']);
Route::post('reset-password/otp', [AuthController::class, 'resetPasswordWithOtp']);


// Route::middleware(['auth:sanctum','role:super_admin'])->get('/monitoring/analytics', [MonitoringController::class, 'analytics']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/change-password', [AuthController::class, 'changePassword']);

    Route::get('/monitoring/analytics', [MonitoringController::class, 'analytics'])
     ->middleware(['auth:sanctum', 'role:super_admin']);

    Route::put('/profile/me', [ProfileController::class, 'update']);          // update profile
    Route::patch('/profile/me', [ProfileController::class, 'update']);        // partial update
    // avatar upload / delete
    Route::post('/profile/avatar', [ProfileController::class, 'avatar']);
    Route::delete('/profile/avatar', [ProfileController::class, 'removeAvatar']);

    Route::post('/editor/upload-image', [EditorUploadController::class, 'store']);
    Route::post('/editor/fetch-image', [EditorUploadController::class, 'fetch']);


    // Full list for tables
    Route::get('/users', [UserController::class, 'index']);
    // Lightweight dropdown for Reports builder
    Route::get('/reports/users', [UserController::class, 'options']);

    Route::get('/settings',  [SettingsController::class, 'show']);
    Route::put('/settings',  [SettingsController::class, 'update']);

    Route::post('/papers/{paper}/highlights/apply', [PdfHighlightController::class, 'apply']);
    Route::post('/pdfs/upload', [PdfHighlightController::class, 'store']); // generic

    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('/dashboard/series/daily', [DashboardController::class, 'dailySeries']);
    Route::get('/dashboard/series/weekly', [DashboardController::class, 'weeklySeries']);

    Route::get('/dashboard/filters', [DashboardController::class, 'filters']);
    Route::get('/dashboard/filters/researchers-by-supervisor', [DashboardController::class, 'researchersBySupervisor']);

    // ✅ Pricing for logged-in user
    Route::get('/pricing', [PricingController::class, 'forCurrentUser']);

    Route::get('/pricing/all', [PricingController::class, 'all']);
    Route::get('/pricing/roles/{role}', [PricingController::class, 'showByRole']);


    // Papers
    Route::get('/papers', [PaperController::class, 'index']);
    Route::post('/papers', [PaperController::class, 'store']);
    Route::get('/papers/{paper}', [PaperController::class, 'show']);
    Route::put('/papers/{paper}', [PaperController::class, 'update']);
    Route::delete('/papers/{paper}', [PaperController::class, 'destroy']);

    Route::post('/library/import', [LibraryImportController::class, 'import']);

    // Paper Files (upload/delete)
    Route::post('/papers/{paper}/files', [PaperFileController::class, 'upload']);
    Route::delete('/papers/{paper}/files/{file}', [PaperFileController::class, 'destroy']);
    Route::post('/papers/bulk-delete', [PaperController::class, 'bulkDestroy']);


    Route::get('/papers/{paper}/comments', [PaperCommentController::class, 'index']);
    Route::post('/papers/{paper}/comments', [PaperCommentController::class, 'store']);
    Route::put('/papers/{paper}/comments/{comment}', [PaperCommentController::class, 'update']);
    Route::delete('/papers/{paper}/comments/{comment}', [PaperCommentController::class, 'destroy']);


    Route::get('/researchers/invites', [ResearcherInviteController::class, 'index']);
    Route::post('/researchers/invites', [ResearcherInviteController::class, 'store']);
    Route::delete('/researchers/invites/{invite}', [ResearcherInviteController::class, 'destroy']);
    Route::post('/researchers/invites/{invite}/resend', [ResearcherInviteController::class, 'resend']);
    // Researcher-side (invited user) endpoints
    Route::get('/researchers/my-invites', [ResearcherInviteController::class, 'myInvites']);
    Route::post('/researchers/invites/{invite}/accept', [ResearcherInviteController::class, 'accept']);
    Route::post('/researchers/invites/{invite}/reject', [ResearcherInviteController::class, 'reject']);

    Route::apiResource('supervisors', SupervisorController::class);

    Route::post('/payment/create-order', [PaymentController::class, 'createOrder']);
    Route::post('/payment/verify', [PaymentController::class, 'verify']);

    // Collections
    Route::get   ('/collections', [CollectionController::class, 'index']);
    Route::post  ('/collections', [CollectionController::class, 'store']);
    Route::get   ('/collections/{collection}', [CollectionController::class, 'show']);
    Route::put   ('/collections/{collection}', [CollectionController::class, 'update']);
    Route::delete('/collections/{collection}', [CollectionController::class, 'destroy']);

    // Items / Papers inside a collection
    Route::get   ('/collections/{collection}/papers', [CollectionController::class, 'papers']);
    Route::post  ('/collections/{collection}/items',  [CollectionController::class, 'addItem']);      // single or bulk
    Route::delete('/collections/{collection}/items/{paper}', [CollectionController::class, 'removeItem']);

    Route::post  ('/collections/{collection}/papers', [CollectionController::class, 'addItem']);      // alias of /items
    Route::delete('/collections/{collection}/papers/{paper}', [CollectionController::class, 'removeItem']); // alias of /items/{paper}
    Route::post('/collections/{collection}/items',  [CollectionController::class, 'addItem']);
    Route::delete('/collections/{collection}/items/{paper}', [CollectionController::class, 'removeItem']);

    // Reorder by paper ID array
    Route::put   ('/collections/{collection}/reorder', [CollectionController::class, 'reorder']);

    // Chapters
    Route::get('/chapters', [ChapterController::class, 'index']);
    Route::post('/chapters', [ChapterController::class, 'store']);
    Route::get('/chapters/{chapter}', [ChapterController::class, 'show']);
    Route::put('/chapters/{chapter}', [ChapterController::class, 'update']);
    Route::delete('/chapters/{chapter}', [ChapterController::class, 'destroy']);
    Route::post('/chapters/{chapter}/items', [ChapterController::class, 'addItem']);
    Route::delete('/chapters/{chapter}/items/{item}', [ChapterController::class, 'removeItem']);
    Route::post('/chapters/reorder', [ChapterController::class, 'reorder']);


    Route::get('/reports/chapters', [ChapterController::class, 'chapterOptions']);


    // Users - list + show
    Route::get('/monitoring/users', [UserController::class, 'index']);
    Route::get('/monitoring/users/{user}', [UserController::class, 'show']);

    Route::get('/monitoring/payments', [PaymentController::class, 'index']);
    Route::get('/monitoring/payments/{payment}', [PaymentController::class, 'show']);


    // ROL exports
    Route::get('/reports/rol.xlsx', [ROLController::class, 'exportXlsx']);
    Route::get('/reports/rol.docx', [ROLController::class, 'exportDocx']);

    // queue
    Route::get('/reviews/queue', [ReviewQueueController::class, 'index']);
    Route::post('/reviews/queue', [ReviewQueueController::class, 'store']);
    Route::delete('/reviews/queue/{paper}', [ReviewQueueController::class, 'destroy']);

    // review load & full update
    Route::get('/reviews/{paper}', [ReviewController::class, 'show']);
    Route::put('/reviews/{paper}', [ReviewController::class, 'update']);

    // NEW: per-tab save
    Route::put('/reviews/{paper}/sections', [ReviewController::class, 'updateSection']);
    Route::get('/reviews/{paper}/sections', [ReviewController::class, 'sections']);

    Route::put('/reviews/{paper}/status', [ReviewController::class, 'updateStatus']); // NEW

        // Lists used by the UI
        Route::get('/reports/rol',        [ReportController::class, 'rol']);           // already had
        Route::get('/reports/literature', [ReportController::class, 'literature']);    // new
        Route::get('/reports/users',      [UserController::class, 'options']);         // for multi-select
        Route::get('/reports/chapters',   [ChapterController::class, 'index']);        // for multi-select

        // Builder (adhoc)
        Route::post('/reports/preview',   [ReportController::class, 'preview']);
        Route::post('/reports/generate',  [ReportController::class, 'generate']);
        Route::post('/reports/bulk-export', [ReportController::class, 'bulkExport']);

        // Saved report configs
        Route::get('/reports/saved',            [SavedReportController::class, 'index']);
        Route::post('/reports/saved',           [SavedReportController::class, 'store']);

        Route::post('/debug/echo', function (\Illuminate\Http\Request $r) {
            \Illuminate\Support\Facades\Log::debug('[DEBUG/ECHO] request all()', $r->all());
            return response()->json([
                'all' => $r->all(),
                'headers' => [
                    'Content-Type' => $r->header('Content-Type'),
                    'Accept'       => $r->header('Accept'),
                ],
            ]);
        });

        Route::get('/reports/saved/{id}',       [SavedReportController::class, 'show']);
        Route::put('/reports/saved/{id}',       [SavedReportController::class, 'update']);
        Route::delete('/reports/saved/{id}',    [SavedReportController::class, 'destroy']);
        Route::post('/reports/saved/{id}/preview',  [ReportController::class, 'preview']);
        Route::post('/reports/saved/{id}/generate', [ReportController::class, 'generate']);
        Route::post('/reports/saved/bulk-delete', [SavedReportController::class, 'bulkDestroy']); // optional

});
