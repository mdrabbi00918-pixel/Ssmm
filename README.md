# সুমন ভাই Panel — Render-safe Premium v2

This build intentionally does **not** run `apt-get` or `docker-php-ext-install`. The previous Render failure happened in the Docker build while Debian packages were being resolved. The app therefore uses only PHP extensions available in the official `php:8.3-apache` image and a dependency-free JSON datastore.

Features:
- Customer registration/login
- 30-day persistent remember-login cookie with token rotation
- Live SMMSUN service catalog
- Platform → service-type filtering
- Customer wallet + manual bKash/Nagad deposits
- Admin service pricing control with reset + search
- Advanced admin overview for users/orders/pending deposits/sales
- Admin deposit approval/rejection
- Customer order history
- CSRF protection and password hashing
- Premium responsive UI

## Render environment variables
- `SMM_API_URL=https://my.smmsun.com/api/v2`
- `SMM_API_KEY=` (keep secret)
- `USD_TO_BDT=122`
- `MARKUP_BDT=10`
- `ADMIN_EMAIL=your-admin-email@example.com`
- `ADMIN_PASSWORD=use-a-strong-password`
- `DB_DIR=/var/www/html/data`

## Important persistence note
The JSON datastore is chosen only to make the Docker build work without apt/native database packages. Render web-service local disk is not durable across all redeploy/restart scenarios unless a persistent disk is attached. For real customer balances/payments, move the datastore to PostgreSQL or attach appropriate persistent storage before production use.
