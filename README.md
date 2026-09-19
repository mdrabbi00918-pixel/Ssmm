# Trusted Bazaar — Supercell Game Items Update

এই build-এ আগের Trusted Bazaar/SMM features রাখা হয়েছে এবং নতুন **🎮 সুপারসেল গেম আইটেম** workflow যোগ করা হয়েছে।

## নতুন ফিচার
- Mobile-friendly Supercell Game Items page
- Admin থেকে item name, direct image upload, price, paid access link ও description যোগ
- Admin item edit/delete
- Customer balance থেকে purchase amount atomicভাবে কাটা
- Purchase-এর পরে access link শুধু buyer-এর Purchase History-তে দেখা যায়
- Item বিক্রি হওয়ার সঙ্গে সঙ্গে `active=false` হয়; অন্য customer আর item দেখতে/কিনতে পারে না
- Customer-এর Supercell Purchase History + Copy Link
- Admin-এর Supercell sales/purchase history ও মোট sales
- Admin quick buttons: Payment Number Change, Price Control, Add Supercell Item, Accept Payment Request
- bKash/Nagad payment number settings আগের মতো কাজ করে
- Deposit request Approve/Reject আগের মতো কাজ করে
- Customer Telegram Help: @Rayhanvai120
- Provider service catalogue caching রাখা হয়েছে যাতে SMM API বারবার call না হয়

## Production
Render/Supabase deployment-এর জন্য আগের `DATABASE_URL`, `SMM_API_URL`, `SMM_API_KEY`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` environment variables একইভাবে ব্যবহার করুন।


## এই আপডেট
- একই Transaction ID দিয়ে দ্বিতীয় payment/deposit request করা যাবে না (case/space পরিবর্তন করলেও একই ID ধরা হবে)।
- Supercell customer page-এর “ছবি, নাম ও দাম সবাই দেখতে পারবে…” explanatory note সরানো হয়েছে; বাকি item/purchase workflow অপরিবর্তিত।
- Database schema initialization প্রতি request-এ বারবার না চালিয়ে container-level marker ব্যবহার করা হয়েছে, যাতে Render/Supabase page load দ্রুত হয়।
- Static CSS/JS/SVG/image assets-এর জন্য browser cache headers যোগ করা হয়েছে।
