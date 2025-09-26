# Notification System Setup Guide

This guide will walk you through the complete setup and understanding of the notification system in this project, including database, backend, and frontend (PWA push notifications, email, and in-app notifications).

---

## 1. Overview

The notification system supports:
- **Database notifications** (in-app)
- **Email notifications**
- **Push notifications** (PWA/Web Push)
- **User preferences** (enable/disable channels, types)
- **Admin/user APIs** for sending, reading, and managing notifications

---

## 2. Backend Setup (Laravel)

### 2.1. Database Migrations
- **Tables:**
  - `notifications` (stores notification data)
  - `notification_user` (pivot: user-notification, read status, sent status)
  - `notification_preferences` (user preferences)
  - `push_subscriptions` (stores browser push subscriptions)

**Files:**
- `database/migrations/xxxx_xx_xx_create_notifications_table.php`
- `database/migrations/xxxx_xx_xx_create_notification_user_table.php`
- `database/migrations/xxxx_xx_xx_create_notification_preferences_table.php`
- `database/migrations/xxxx_xx_xx_create_push_subscriptions_table.php`

**Run migrations:**
```bash
php artisan migrate
```

### 2.2. Models
- `App\Models\Notification`
- `App\Models\PushSubscription`
- `App\Models\NotificationPreference`
- Update `App\Models\User` with relationships:
  - `notifications()`, `pushSubscriptions()`, `notificationPreferences()`

### 2.3. Notification Service
- `App\Services\NotificationService.php`
  - Handles sending notifications via database, email, and push
  - Uses [minishlink/web-push](https://github.com/web-push-libs/web-push-php) for push
  - Handles marking subscriptions inactive if expired

### 2.4. Controllers
- `App\Http\Controllers\NotificationController.php` (user endpoints)
- `App\Http\Controllers\Admin\NotificationController.php` (admin endpoints)

### 2.5. Routes
- `routes/api.php`:
  - `/api/notifications/...` (user)
  - `/api/admin/notifications/...` (admin)

### 2.6. Email Template
- `resources/views/emails/notification.blade.php`

### 2.7. Web Push Configuration
- Install package:
  ```bash
  composer require minishlink/web-push
  ```
- Generate VAPID keys:
  ```bash
  php artisan webpush:vapid
  ```
- Add to `.env`:
  ```env
  VAPID_PUBLIC_KEY=...
  VAPID_PRIVATE_KEY=...
  ```
- Add to `config/services.php`:
  ```php
  'webpush' => [
      'public_key' => env('VAPID_PUBLIC_KEY'),
      'private_key' => env('VAPID_PRIVATE_KEY'),
  ],
  ```

### 2.8. Broadcasting (for real-time, optional)
- Configure `broadcasting.php` for Pusher or Laravel Echo if you want real-time updates

### 2.9. Seeder (optional)
- Seed default notification preferences in `DatabaseSeeder.php`

---

## 3. Frontend Setup (Quasar/Vue)

### 3.1. Service Worker
- `public/sw.js` (handles push events, shows notifications)
- Register service worker in `App.vue` or main entry

### 3.2. Push Subscription
- Use `navigator.serviceWorker` and `PushManager` to subscribe
- Send subscription to backend via `/api/notifications/push-subscription`
- Store subscription in Pinia store or Vuex

### 3.3. Notification Store
- `src/stores/notifications.js` (Pinia)
  - Handles API calls for notifications, preferences, push subscription

### 3.4. Notification UI
- `src/pages/UserNotificationsPage.vue` (user notification center)
- `src/pages/AdminNotificationsPage.vue` (admin send/monitor notifications)
- Use Quasar components for lists, chips, buttons, etc.

### 3.5. Push Notification Permission
- Prompt user to enable push notifications
- Show status (enabled/disabled)
- Handle permission denied

### 3.6. Handling Push Events
- In `sw.js`, listen for `push` and `notificationclick` events
- Show notification using `self.registration.showNotification()`
- Optionally, handle notification click to open app or a specific page

### 3.7. Notification Preferences
- UI for user to enable/disable channels/types
- Save preferences via `/api/notifications/preferences`

### 3.8. Testing
- Use the "Test Push" button in the notification page
- Send test notifications from admin panel
- Check browser notification permission and subscription status

---

## 4. Step-by-Step: Sending a Push Notification

1. **User enables push notifications in the app**
   - Browser asks for permission
   - Service worker is registered
   - Subscription is created and sent to backend
2. **Admin sends a notification to the user**
   - Admin selects user(s), fills out notification form, sends
   - Backend creates notification, checks user preferences
   - If push is enabled and subscription is valid, backend sends push via WebPush
3. **User receives notification**
   - Service worker receives push event
   - Notification is shown in browser
   - User can click notification to open app
4. **User sees notification in-app**
   - Notification appears in notification center (UserNotificationsPage)
   - User can mark as read, manage preferences

---

## 5. Troubleshooting

- **Push not received?**
  - Check browser permission (should be "granted")
  - Check if subscription exists in DB (`push_subscriptions` table)
  - Check Laravel logs for errors (410 Gone = expired subscription)
  - Try unsubscribing and re-enabling push notifications
- **Email not received?**
  - Check mail config in `.env`
  - Check logs for errors
- **In-app notification not showing?**
  - Check API response in browser dev tools
  - Check Pinia store and API endpoints
- **Subscription expired?**
  - System will mark as inactive; user must re-enable push

---

## 6. Files Added/Updated (Summary)

**Backend:**
- `app/Models/Notification.php`
- `app/Models/PushSubscription.php`
- `app/Models/NotificationPreference.php`
- `app/Services/NotificationService.php`
- `app/Http/Controllers/NotificationController.php`
- `app/Http/Controllers/Admin/NotificationController.php`
- `database/migrations/*_notifications_*.php`
- `database/migrations/*_push_subscriptions_*.php`
- `database/migrations/*_notification_preferences_*.php`
- `resources/views/emails/notification.blade.php`
- `routes/api.php`
- `config/services.php`

**Frontend:**
- `public/sw.js`
- `src/stores/notifications.js`
- `src/pages/UserNotificationsPage.vue`
- `src/pages/AdminNotificationsPage.vue`
- Service worker registration in `App.vue` or main.js

---

## 7. References
- [Web Push Protocol](https://developers.google.com/web/fundamentals/push-notifications)
- [Minishlink WebPush PHP](https://github.com/web-push-libs/web-push-php)
- [Quasar PWA Docs](https://quasar.dev/quasar-cli-vite/developing-pwa/introduction)

---

## 8. Need Help?
If you get stuck, check the Laravel logs, browser console, and network tab. Most issues are due to expired subscriptions, browser permission, or misconfiguration.

---

**You now have a robust, multi-channel notification system!** 
