<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;

class DashboardNotificationTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    public function test_admin_failure_threshold_email_is_sent_to_admin_email()
    {
        // Create a dummy user (any user, since email is routed to ADMIN_EMAIL)
        $user = \App\Models\User::factory()->create();

        // Prepare notification data
        $thresholdData = [
            'threshold' => 3,
            'current_count' => 5,
            'time_window_minutes' => 60,
            'pos_terminal_id' => 'POS123'
        ];

        // Fake notifications
        \Illuminate\Support\Facades\Notification::fake();

        // Send notification
        $user->notify(new \App\Notifications\TransactionFailureThresholdExceeded($thresholdData));

        // Assert notification sent to user and routed to ADMIN_EMAIL
        \Illuminate\Support\Facades\Notification::assertSentTo(
            [$user],
            \App\Notifications\TransactionFailureThresholdExceeded::class,
            function ($notification, $channels) use ($user) {
                return in_array('mail', $channels) &&
                       $notification->routeNotificationForMail($user) === env('ADMIN_EMAIL');
            }
        );
    }
    use RefreshDatabase, WithFaker;

    public function test_admin_sees_excessive_failure_notification_box()
    {
        // Create admin user
        $admin = User::factory()->create(['name' => 'Admin User']);
        if (class_exists('Spatie\\Permission\\Models\\Role')) {
            $roleClass = \Spatie\Permission\Models\Role::class;
            if (!$roleClass::where('name', 'admin')->where('guard_name', 'web')->exists()) {
                $roleClass::create(['name' => 'admin', 'guard_name' => 'web']);
            }
            if (method_exists($admin, 'assignRole')) {
                $admin->assignRole('admin');
            }
        }

        // Simulate notification in DB (unread, with UUID id)
        $uuid = (string) \Illuminate\Support\Str::uuid();
        \DB::table('notifications')->insert([
            'id' => $uuid,
            'type' => 'App\\Notifications\\TransactionFailureThresholdExceeded',
            'notifiable_id' => $admin->id,
            'notifiable_type' => 'App\\Models\\User',
            'data' => json_encode([
                'type' => 'transaction_failure_threshold_exceeded',
                'severity' => 'high',
                'pos_terminal_id' => 123,
                'threshold_data' => ['current_count' => 5]
            ]),
            'created_at' => now(),
            'updated_at' => now(),
            'read_at' => null
        ]);

        // Visit dashboard as admin
        $response = $this->actingAs($admin)->get('/dashboard');
        $response->assertStatus(200);
        $response->assertSee('exceeded failure threshold');
        $response->assertSee('Severity:');
        $response->assertSee('Count:');
        $response->assertSee('Dismiss');
    }
}