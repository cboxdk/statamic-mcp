<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Users;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Stache;
use Statamic\Facades\User;

#[Title('Activate User')]
class ActivateUserTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.users.activate';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Activate or deactivate user accounts with optional email notifications';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('user_id')
            ->description('User ID or email to activate/deactivate')
            ->required()
            ->boolean('activate')
            ->description('True to activate, false to deactivate')
            ->optional()
            ->boolean('send_notification')
            ->description('Send email notification about status change')
            ->optional()
            ->string('notification_message')
            ->description('Custom message to include in notification email')
            ->optional()
            ->boolean('force_password_reset')
            ->description('Force user to reset password on next login (activation only)')
            ->optional();
    }

    /**
     * Execute the tool logic.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $userId = $arguments['user_id'];
        $activate = $arguments['activate'] ?? true;
        $sendNotification = $arguments['send_notification'] ?? false;
        $notificationMessage = $arguments['notification_message'] ?? null;
        $forcePasswordReset = $arguments['force_password_reset'] ?? false;

        try {
            // Find the user
            $user = User::find($userId) ?? User::findByEmail($userId);

            if (! $user) {
                return $this->createErrorResponse("User '{$userId}' not found")->toArray();
            }

            $originalStatus = $user->isActivated() ?? true;
            $statusChanged = false;
            $emailSent = false;
            $emailError = null;

            // Check if activation methods are available
            $hasActivationMethods = method_exists($user, 'activate') && method_exists($user, 'deactivate');

            if (! $hasActivationMethods) {
                return $this->createErrorResponse('User activation/deactivation methods not available', [
                    'note' => 'This may require additional packages or configuration',
                ])->toArray();
            }

            // Perform activation/deactivation
            if ($activate && ! $originalStatus) {
                $user->activate();
                $statusChanged = true;

                // Force password reset if requested
                if ($forcePasswordReset && method_exists($user, 'setPasswordResetToken')) {
                    $user->setPasswordResetToken();
                }
            } elseif (! $activate && $originalStatus) {
                $user->deactivate();
                $statusChanged = true;
            }

            if ($statusChanged) {
                $user->save();
                Stache::clear();

                // Send notification if requested
                if ($sendNotification) {
                    try {
                        $this->sendActivationNotification($user, $activate, $notificationMessage);
                        $emailSent = true;
                    } catch (\Exception $e) {
                        $emailError = 'Failed to send notification: ' . $e->getMessage();
                    }
                }
            }

            return [
                'success' => true,
                'user' => [
                    'id' => $user->id(),
                    'email' => $user->email(),
                    'name' => $user->name(),
                    'is_activated' => $user->isActivated() ?? true,
                ],
                'status_changed' => $statusChanged,
                'action' => $activate ? 'activated' : 'deactivated',
                'notification_sent' => $emailSent,
                'notification_error' => $emailError,
                'password_reset_required' => $activate && $forcePasswordReset,
                'previous_status' => $originalStatus,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to change user activation status: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Send activation/deactivation notification.
     *
     * @param  \Statamic\Contracts\Auth\User  $user
     *
     * @throws \Exception
     */
    private function sendActivationNotification($user, bool $activate, ?string $customMessage): void
    {
        // This is a basic implementation - you might want to use Laravel's
        // notification system or Statamic's email capabilities
        $subject = $activate ? 'Account Activated' : 'Account Deactivated';
        $status = $activate ? 'activated' : 'deactivated';

        $message = "Hello {$user->name()},\n\n";
        $message .= "Your account has been {$status}.\n\n";

        if ($customMessage) {
            $message .= $customMessage . "\n\n";
        }

        if ($activate) {
            $message .= "You can now log in to your account.\n\n";
        } else {
            $message .= "You will no longer be able to access your account until it is reactivated.\n\n";
        }

        $message .= "If you have any questions, please contact support.\n\n";
        $message .= "Best regards,\nThe Team";

        // Use Laravel's Mail facade or Statamic's email system
        if (class_exists('Illuminate\Support\Facades\Mail')) {
            \Illuminate\Support\Facades\Mail::raw($message, function ($mail) use ($user, $subject) {
                $mail->to($user->email(), $user->name())
                    ->subject($subject);
            });
        } else {
            throw new \Exception('Email system not available');
        }
    }
}
