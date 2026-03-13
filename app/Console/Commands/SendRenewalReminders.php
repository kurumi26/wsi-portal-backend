<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CustomerService;
use App\Models\PortalNotification;
use Carbon\Carbon;

class SendRenewalReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'portal:send-renewal-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send automated renewal reminders to customers at configured intervals before renewal.';

    /**
     * Reminder intervals (days before renewal)
     *
     * @var array
     */
    protected $intervals = [90, 60, 30, 15, 10, 5, 3, 1, 0];

    public function handle()
    {
        $this->info('Starting renewal reminder job');

        $now = Carbon::today();

        $services = CustomerService::query()
            ->whereNotNull('renews_on')
            ->where('status', '!=', 'Expired')
            ->get();

        foreach ($services as $service) {
            try {
                $renewsOn = Carbon::parse($service->renews_on)->startOfDay();

                if ($renewsOn->lt($now)) {
                    // already past due
                    $daysRemaining = -1;
                } else {
                    $daysRemaining = $renewsOn->diffInDays($now);
                }

                if (! in_array($daysRemaining, $this->intervals, true)) {
                    continue;
                }

                $userId = $service->user_id;

                $label = $daysRemaining === 0 ? 'Renewal due today' : "Renewal in {$daysRemaining} day" . ($daysRemaining > 1 ? 's' : '');

                if ($daysRemaining === 0) {
                    $dueText = 'due for renewal today';
                } else {
                    $dueText = 'due for renewal in ' . $daysRemaining . ' day' . ($daysRemaining > 1 ? 's' : '');
                }
                $message = "[svc:{$service->id}] Your service \"{$service->name}\" is {$dueText}. Please ensure payment or renewal to avoid disruption.";

                // avoid duplicate reminders for same service and interval
                $exists = PortalNotification::query()
                    ->where('user_id', $userId)
                    ->where('title', $label)
                    ->where('message', 'like', "%[svc:{$service->id}]%")
                    ->exists();

                if ($exists) {
                    continue;
                }

                PortalNotification::create([
                    'user_id' => $userId,
                    'title' => $label,
                    'message' => $message,
                    'type' => 'reminder',
                ]);
            } catch (\Exception $e) {
                // Log and continue
                $this->error('Failed processing service '.$service->id.': '.$e->getMessage());
                continue;
            }
        }

        $this->info('Renewal reminder job completed');
        return 0;
    }
}
