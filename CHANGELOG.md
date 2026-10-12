# Changelog

This file lists the notable changes to the Thryft backend, newest first.

## 2026-10-08

### Added
- Paid featured placement near the business
- Joint promotions, organizations and a partner directory
- Gift certificates and memberships, paid for at the business
- Digital loyalty cards, stamped at the till
- Several locations per business
- Referral free months, applied automatically
- Public pages use the brand colour from Settings

### Fixed
- Event times show in the host's time zone, pages have a single header, and percentages are whole numbers
- Partner and member lists show the business logo instead of the owner's avatar
- A business is looked up by its owner's user id first, which is the id the app sends

## 2026-10-01

### Added
- Business page editor

### Fixed
- A mail outage at signup no longer leaves the new account stuck

## 2026-09-30

### Added
- Events
- Flash deals and announcements
- Referral codes, with automatic attribution and qualification
- Guest mode for the app's browse endpoints
- Team management from the app
- Alerts when a saved business posts a new offer, and reminders before claimed coupons expire
- A `/api/health` endpoint, kept in the repo

### Fixed
- The team and attendee lists show a display name

## 2026-09-29

### Added
- Shoppers can save a business
- The home page hero can be edited from admin settings

### Fixed
- Login is no longer blocked by a 2FA setup that was never confirmed
- A claimed coupon's business is looked up through the businesses table
- The nearby-businesses query works on MySQL and SQLite, and matches roles correctly
- The automatic update on `starts_at` is removed before the Stripe backfill touches any rows

## 2026-09-22 – 2026-09-28: hardening

### Security
- Endpoints that were open without authentication now require it
- Login, two-factor and public endpoints are rate-limited
- Fixed a case where 2FA could lock users out

### Added
- Subscriptions sync with Stripe; access is gated on the server and plan limits are enforced
- Businesses are their own entity, with team memberships, policies and an ability-based gate (read path behind `THRYFT_USE_BUSINESS_ENTITY`)
- Crawlable business pages, guest mode and an API resource layer
- Merchant analytics, an onboarding checklist and billing per business
- CI runs on SQLite and MySQL, and again with the business-entity flag on

### Fixed
- Claiming and redeeming a coupon are atomic, respect expiry and can't be ambiguous
- Staff can redeem at the till, because the acting business is now resolved correctly
- Issues found in the audit of the hardening branch

## 2025-09-26 – 2025-11-28: first release

- First version of the API: authentication, admin, coupons, products, categories and notifications
- Brevo SMTP for email; CORS and frontend URL configuration
- Customer search, nearby coupons, and QR code and barcode scanning
- Business categories, business tags with a banner, and a title field for search
- Subscriptions with billing cycles, a free-subscription claim (free until 2025-12-27) and a remaining-days counter
