# সুমন ভাই Panel — Main v1

Full starter reseller panel with:
- Customer registration/login
- Wallet balance
- Manual bKash/Nagad deposit requests
- Admin deposit approval/rejection
- Live SMMSUN service catalog
- ৳10 markup per 1,000 units
- Admin can set a custom price per service from the Live Service Pricing table
- Platform → service-type filters (e.g. TikTok: Followers/Views/Likes/Subscribers; YouTube: Subscribers/Views/Likes)
- 30-day persistent login with secure, rotating remember token
- Premium responsive UI with nested service categories
- Authenticated order placement to SMMSUN API
- Customer order history
- Admin recent orders
- CSRF protection and password hashing

## Render environment variables
- `SMM_API_URL=https://my.smmsun.com/api/v2`
- `SMM_API_KEY=` (keep private; never put in GitHub)
- `USD_TO_BDT=122`
- `MARKUP_BDT=10`
- `ADMIN_EMAIL=your-admin-email@example.com`
- `ADMIN_PASSWORD=use-a-strong-password`
- `DB_DIR=/var/www/html/data`

### Important
This version uses SQLite for a working prototype. Render's local filesystem is not persistent across all redeploy/restart scenarios. For production, move the database to PostgreSQL before taking real customer payments at scale.

Payment is manual in this version: customers submit amount + transaction ID and an admin approves it. No bKash/Nagad API credentials are included.

### Pricing
The admin panel loads the live provider catalog and lets the admin set a customer-facing BDT price per 1,000 for each service. Reset removes the override and returns to `rate × USD_TO_BDT + MARKUP_BDT`.

### Persistent login
Successful logins create a secure 30-day HttpOnly/SameSite remember cookie. The token is stored hashed in SQLite and rotated when restored. Logout revokes the persistent token.
