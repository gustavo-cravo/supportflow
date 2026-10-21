<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PendingTaskResource;
use App\Http\Resources\ReminderResource;
use App\Http\Resources\SupportTicketResource;
use App\Models\PendingTask;
use App\Models\Reminder;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        $statuses = SupportTicket::query()->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $tasks = PendingTask::with('supportTicket')->where('status', 'pending')->where('due_at', '<', now());
        $today = Reminder::with('supportTicket')->whereNull('completed_at')->where('scheduled_at', '>=', today())->where('scheduled_at', '<', today()->addDay());

        return response()->json(['data' => [
            'counts' => [
                'open' => (int) ($statuses['open'] ?? 0),
                'in_progress' => (int) ($statuses['in_progress'] ?? 0),
                'waiting' => (int) ($statuses['waiting'] ?? 0),
                'overdue_tasks' => (clone $tasks)->count(),
                'today_reminders' => (clone $today)->count(),
            ],
            'recent_tickets' => SupportTicketResource::collection(SupportTicket::with(['system', 'contact', 'customer'])->latest('id')->limit(5)->get())->resolve(),
            'overdue_tasks' => PendingTaskResource::collection($tasks->orderBy('due_at')->orderBy('id')->limit(5)->get())->resolve(),
            'today_reminders' => ReminderResource::collection($today->orderBy('scheduled_at')->orderBy('id')->limit(5)->get())->resolve(),
            'overdue_reminders' => ReminderResource::collection(Reminder::with('supportTicket')->whereNull('completed_at')->where('scheduled_at', '<', now())->orderBy('scheduled_at')->orderBy('id')->limit(5)->get())->resolve(),
            'upcoming_reminders' => ReminderResource::collection(Reminder::with('supportTicket')->whereNull('completed_at')->where('scheduled_at', '>=', today()->addDay())->orderBy('scheduled_at')->orderBy('id')->limit(5)->get())->resolve(),
        ]]);
    }
}
