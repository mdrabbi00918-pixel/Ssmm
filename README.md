# BAZAAR Panel – Customer & Admin

## Separate panels
- Customer panel entry: `customer.php`
- Admin panel entry: `admin.php`
- Main application: `index.php`

## Updated features
- Rainbow mixed-color responsive customer dashboard.
- Separate admin dashboard with admin-only authorization.
- Admin can change bKash and Nagad payment numbers from **Payment Settings**.
- Customer deposit page automatically shows the saved payment numbers.
- Admin can promote customers to Admin or change Admins back to Customer.
- Current admin cannot remove their own admin access.
- The previous `MH` branding has been removed.
- Existing service pricing, deposits, orders, login/session and API functionality remain.


## Customer service allowlist
The Customer Panel now displays and accepts orders only for these service IDs:
1086, 1076, 1762, 104, 105, 1367, 1368, 664, 665, 3901, 3902, 851, 854, 1049, 242, 125, 133, 1205, 265, 234, 463, 448, 1050, 474, 462, 476, 3847, 531, 537, 2600, 629, 3313, 2194, 2945, 369, 1722, 1726, 9445, 9453, 1849.

## Login persistence
Website passwords are stored as secure password hashes, not as plain-text Gmail passwords. The Render configuration now mounts a persistent data disk at `/var/www/html/data` so registered accounts and remember-login tokens survive normal service restarts/redeploys. A separate website password should be used; the site does not access or store a user's actual Google/Gmail account password.


## Production database
- Set `DATABASE_URL` in Render to the Supabase **Session Pooler** PostgreSQL URI.
- The app automatically creates its tables on first boot. No SQL import is required.
- Customer passwords are stored as secure PHP password hashes. The site never stores or uses the customer's real Gmail password.
- Login uses a secure 30-day rotating remember token stored in PostgreSQL, so a Render restart/redeploy does not make the customer account disappear.
- Set `SMM_API_URL` to `https://my.smmsun.com/api/v2` and put the provider key only in Render as `SMM_API_KEY`.
- Do not put database or API secrets directly in the ZIP or source code.
