<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends VerifyEmail
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Get the verification email notification mail message for the given URL.
     *
     * @param  string  $url
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    protected function buildMailMessage($url)
    {
        return (new MailMessage)
            ->subject('Verify Email Address')
            ->line('Please click the button below to verify your email address.')
            ->action('Verify Email Address', $url)
            ->line('If you did not create an account, no further action is required.');
    }

    /**
     * Get the verification URL for the given notifiable.
     *
     * @param  mixed  $notifiable
     * @return string
     */
    protected function verificationUrl($notifiable)
    {
        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:9000'), '/');

        $verifyUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );

        // Extract the query parameters from the Laravel URL
        $query = parse_url($verifyUrl, PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $params);

            // Build the frontend URL with the verification parameters
            return $frontendUrl . '/auth/verify-email?' . http_build_query([
                'id' => $params['id'] ?? $notifiable->getKey(),
                'hash' => $params['hash'] ?? sha1($notifiable->getEmailForVerification()),
                'expires' => $params['expires'] ?? '',
                'signature' => $params['signature'] ?? '',
            ]);
        }

        // Fallback if query parsing fails
        return $frontendUrl . '/auth/verify-email?' . http_build_query([
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
        ]);
    }
}
