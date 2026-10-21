<?php

use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\KnowledgeArticleController;
use App\Http\Controllers\Api\PendingTaskController;
use App\Http\Controllers\Api\ReminderController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\SystemController;
use Illuminate\Support\Facades\Route;

Route::get('dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');

Route::apiResource('systems', SystemController::class)->except('destroy');
Route::apiResource('contacts', ContactController::class)->except('destroy');
Route::apiResource('customers', CustomerController::class)->except('destroy');
Route::apiResource('support-tickets', SupportTicketController::class)
    ->parameters(['support-tickets' => 'supportTicket'])->except('destroy');
Route::patch('support-tickets/{supportTicket}/status', [SupportTicketController::class, 'status'])->name('support-tickets.status');
Route::post('support-tickets/{supportTicket}/close', [SupportTicketController::class, 'close'])->name('support-tickets.close');
Route::get('support-tickets/{supportTicket}/related', [SupportTicketController::class, 'related'])->name('support-tickets.related');
Route::apiResource('pending-tasks', PendingTaskController::class)
    ->parameters(['pending-tasks' => 'pendingTask'])->except('destroy');
Route::post('pending-tasks/{pendingTask}/complete', [PendingTaskController::class, 'complete'])->name('pending-tasks.complete');
Route::apiResource('reminders', ReminderController::class)->except('destroy');
Route::post('reminders/{reminder}/complete', [ReminderController::class, 'complete'])->name('reminders.complete');
Route::apiResource('knowledge-articles', KnowledgeArticleController::class)
    ->parameters(['knowledge-articles' => 'knowledgeArticle'])->except('destroy');
