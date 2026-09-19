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
