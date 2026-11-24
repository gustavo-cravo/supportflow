<?php

use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExportSupportTicketPdfController;
use App\Http\Controllers\Api\ExportTicketMetricsController;
use App\Http\Controllers\Api\KnowledgeArticleController;
use App\Http\Controllers\Api\PendingTaskController;
use App\Http\Controllers\Api\ProblemCategoryController;
use App\Http\Controllers\Api\ProblemSubcategoryController;
use App\Http\Controllers\Api\ReminderController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TicketMetricsController;
use App\Http\Controllers\Api\VehicleBrandController;
use App\Http\Controllers\Api\VehicleController;
use Illuminate\Support\Facades\Route;

Route::get('dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
Route::get('metrics/export', ExportTicketMetricsController::class)->name('metrics.export');

Route::prefix('metrics')->name('metrics.')->controller(TicketMetricsController::class)->group(function () {
    Route::get('summary', 'summary')->name('summary');
    Route::get('systems', 'systems')->name('systems');
    Route::get('categories', 'categories')->name('categories');
    Route::get('subcategories', 'subcategories')->name('subcategories');
    Route::get('monthly', 'monthly')->name('monthly');
    Route::get('vehicles', 'vehicles')->name('vehicles');
    Route::get('tickets', 'tickets')->name('tickets');
});

Route::apiResource('systems', SystemController::class)->except('destroy');
Route::apiResource('problem-categories', ProblemCategoryController::class)
    ->parameters(['problem-categories' => 'problemCategory'])->except('destroy');
Route::apiResource('problem-subcategories', ProblemSubcategoryController::class)
    ->parameters(['problem-subcategories' => 'problemSubcategory'])->except('destroy');
Route::apiResource('contacts', ContactController::class)->except('destroy');
Route::apiResource('customers', CustomerController::class)->except('destroy');
Route::apiResource('vehicle-brands', VehicleBrandController::class)
    ->parameters(['vehicle-brands' => 'vehicleBrand'])->except('destroy');
Route::apiResource('vehicles', VehicleController::class)->except('destroy');
Route::get('vehicles/{vehicle}/support-tickets', [SupportTicketController::class, 'vehicleHistory'])->name('vehicles.tickets');
Route::apiResource('support-tickets', SupportTicketController::class)
    ->parameters(['support-tickets' => 'supportTicket'])->except('destroy');
Route::patch('support-tickets/{supportTicket}/status', [SupportTicketController::class, 'status'])->name('support-tickets.status');
Route::post('support-tickets/{supportTicket}/close', [SupportTicketController::class, 'close'])->name('support-tickets.close');
Route::post('support-tickets/{supportTicket}/export/pdf', ExportSupportTicketPdfController::class)->name('support-tickets.export.pdf');
Route::get('support-tickets/{supportTicket}/related', [SupportTicketController::class, 'related'])->name('support-tickets.related');
Route::apiResource('pending-tasks', PendingTaskController::class)
    ->parameters(['pending-tasks' => 'pendingTask'])->except('destroy');
Route::post('pending-tasks/{pendingTask}/complete', [PendingTaskController::class, 'complete'])->name('pending-tasks.complete');
Route::apiResource('reminders', ReminderController::class)->except('destroy');
Route::post('reminders/{reminder}/complete', [ReminderController::class, 'complete'])->name('reminders.complete');
Route::apiResource('knowledge-articles', KnowledgeArticleController::class)
    ->parameters(['knowledge-articles' => 'knowledgeArticle'])->except('destroy');
