"""Generate standard WordPress PHP translation catalogs and gettext templates."""
import json
import re
from pathlib import Path

root = Path(__file__).resolve().parents[1] / 'wp-content/plugins/ani-sms'
translations = {
    'Test code created. No SMS was sent.': 'کد آزمایشی ساخته شد. پیامکی ارسال نشده است.',
    'Local OTP test mode is active. No SMS is sent; codes are shown on the local form. Open the login page in a private window to test registration without changing your administrator session.': 'حالت تست لوکال OTP فعال است. پیامکی ارسال نمی‌شود و کد روی فرم لوکال نمایش داده می‌شود. برای آزمون ثبت‌نام، برگه ورود را در پنجره ناشناس باز کنید تا نشست مدیر شما تغییر نکند.',
    'Local OTP testing requires a local environment, a loopback site URL and a loopback connection.': 'حالت تست OTP فقط با محیط local، نشانی سایت localhost و اتصال loopback مجاز است.',
    'Local test code (no SMS sent):': 'کد تست لوکال (پیامکی ارسال نشده):',
    'Local test mode: no SMS is sent. Test codes appear here. New registrations create real local WordPress accounts. Privileged accounts still use the standard login.': 'حالت تست لوکال: پیامکی ارسال نمی‌شود و کد آزمایشی همین‌جا نمایش داده می‌شود. ثبت‌نام، حساب واقعی در وردپرس محلی می‌سازد. برای آزمون از پنجره ناشناس استفاده کنید؛ حساب مدیر از ورود استاندارد استفاده می‌کند.',
    'Last privacy cleanup:': 'آخرین پاک‌سازی داده‌ها:',
    'Not run yet': 'هنوز اجرا نشده',
    'Privacy cleanup is delayed. Check the site cron before relying on retention limits.': 'پاک‌سازی داده‌ها با تأخیر مواجه است. برای رعایت زمان نگهداری، cron سایت را بررسی کنید.',
    'Your mobile is already verified. Changing a linked number is not offered in this release.': 'شماره موبایل شما تأیید شده است. تغییر شماره متصل‌شده در این نسخه ارائه نمی‌شود.',
    'Publish and configure a privacy policy page before enabling mobile authentication.': 'پیش از فعال‌سازی ورود پیامکی، برگه حریم خصوصی را منتشر و در تنظیمات وردپرس انتخاب کنید.',
    'ANI-SMS processes mobile numbers to verify ownership. SMS.ir receives the destination and template parameters to send the requested message. OTP codes are never stored in plaintext. Expired challenges are removed after one hour, rate metadata within 24 hours after its window, and masked message metadata after 30 days. Identity links remain until removed. Login is not marketing consent. Data is preserved on uninstall unless the administrator explicitly enables plugin-data deletion. Server configuration and backups may retain credentials; handle them separately. Users without an email address can request identity-link removal through an administrator after identity verification.': 'ANI-SMS شماره موبایل را برای تأیید مالکیت پردازش می‌کند. برای ارسال پیامک، شماره مقصد و پارامترهای قالب به SMS.ir فرستاده می‌شوند. کدها به‌صورت متن قابل بازیابی ذخیره نمی‌شوند. داده کدهای منقضی حداکثر تا یک ساعت، داده محدودسازی تا ۲۴ ساعت پس از پایان بازه و رخدادهای بدون شماره کامل تا ۳۰ روز با اجرای منظم پاک‌سازی حذف می‌شوند. اتصال شماره تا زمان درخواست حذف باقی می‌ماند. ورود به معنی رضایت بازاریابی نیست. هنگام حذف افزونه، داده‌ها به‌صورت پیش‌فرض حفظ می‌شوند مگر مدیر حذف صریح را فعال کرده باشد. تنظیمات سرور و نسخه‌های پشتیبان ممکن است کلیدها را نگه دارند و باید جداگانه مدیریت شوند. کاربران فاقد ایمیل می‌توانند پس از احراز هویت، از مدیر درخواست حذف اتصال شماره کنند.',
    'Overview': 'نمای کلی',
    'SMS.ir connection': 'اتصال SMS.ir',
    'Login and registration': 'ورود و ثبت‌نام',
    'Modules': 'ماژول‌ها',
    'Local templates': 'قالب‌های محلی',
    'Logs': 'رخدادها',
    'Help': 'راهنما',
    'Connection': 'وضعیت اتصال',
    'Panel credit': 'اعتبار پنل',
    'Accepted today · this plugin': 'پذیرفته‌شده امروز · این افزونه',
    'Errors today · this plugin': 'خطاهای امروز · این افزونه',
    'Not checked': 'بررسی نشده',
    'Connected': 'متصل',
    'Connection failed': 'خطا در اتصال',
    'Unavailable': 'در دسترس نیست',
    'Navigation': 'فهرست',
    'Mobile access, thoughtfully simple.': 'ورود موبایلی، ساده و امن',
    'Changes saved.': 'تغییرات ذخیره شد.',
    'Permission denied.': 'اجازه دسترسی ندارید.',
    'Save changes': 'ذخیره تغییرات',
    'SMS.ir is the only provider. Accepted messages are not confirmed deliveries. Counters cover this plugin in the site timezone.': 'تنها سامانه پیامکی این افزونه SMS.ir است. پذیرش پیامک به معنی تحویل آن نیست. آمار امروز فقط مربوط به این افزونه و بر اساس منطقه زمانی سایت است.',
    'Credit is the balance returned by your panel, not a promised number of SMS. Its billing unit depends on your SMS.ir account.': 'اعتبار، موجودی گزارش‌شده پنل است و تعداد تضمین‌شده پیامک نیست. واحد محاسبه را در حساب SMS.ir خود بررسی کنید.',
    'Last checked:': 'آخرین بررسی:',
    'API key configured. Stored values are never returned to the browser.': 'کلید API تنظیم شده است. مقدار ذخیره‌شده به مرورگر ارسال نمی‌شود.',
    'No usable API key. Configure the server secret before saving credentials.': 'کلید قابل استفاده‌ای موجود نیست. پیش از ذخیره کلید API، کلید رمزگذاری سرور را تنظیم کنید.',
    'Replace API key (leave blank to keep)': 'جایگزینی کلید API (برای حفظ کلید فعلی خالی بگذارید)',
    'Approved OTP template ID': 'شناسه قالب تأییدشده کد یک‌بارمصرف',
    'Exact OTP parameter name': 'نام دقیق پارامتر کد در قالب',
    'Check connection · no SMS': 'آزمون اتصال · بدون ارسال پیامک',
    'Delete database API key': 'حذف کلید API ذخیره‌شده',
    'A server-configured API key takes priority and cannot be deleted here.': 'کلید تعریف‌شده در تنظیمات سرور اولویت دارد و از این صفحه حذف نمی‌شود.',
    'Test recipient mobile': 'شماره موبایل گیرنده آزمایشی',
    'Send one paid test SMS to this number. This code grants no account access.': 'یک پیامک آزمایشی با کسر هزینه به این شماره ارسال شود. این کد امکان ورود به حساب را ندارد.',
    'Send paid test SMS': 'ارسال پیامک آزمایشی با هزینه',
    'Allow new subscriber accounts when WordPress registration is also enabled': 'ایجاد حساب مشترک جدید، مشروط به فعال‌بودن ثبت‌نام وردپرس',
    'Code lifetime (seconds)': 'عمر کد (ثانیه)',
    'Resend cooldown (seconds)': 'فاصله ارسال مجدد (ثانیه)',
    'Maximum attempts per challenge': 'حداکثر تلاش برای هر کد',
    'Site issuance limit per hour': 'سقف صدور کد سایت در هر ساعت',
    'Codes have six digits. Phone/IP limits cannot be reset by resending. Privileged accounts must use the standard login. Already logged-in subscribers may link an unassigned number after password reauthentication and OTP verification; changing an existing link is not offered.': 'کدها شش‌رقمی هستند. ارسال مجدد محدودیت شماره و IP را صفر نمی‌کند. حساب‌های مدیریتی باید از ورود استاندارد استفاده کنند. کاربر واردشده می‌تواند پس از تأیید رمز فعلی و کد پیامکی، شماره آزاد را به حساب متصل کند؛ تغییر شماره متصل‌شده ارائه نمی‌شود.',
    'Authentication dependencies are available.': 'پیش‌نیازهای ورود پیامکی در دسترس‌اند.',
    'Enable mobile authentication': 'فعال‌سازی ورود پیامکی',
    'Save module state': 'ذخیره وضعیت ماژول',
    'Local template: account verification. Create and approve the template in SMS.ir first. Configure its ID and exact code parameter under Connection. This plugin never creates, edits or deletes panel templates.': 'قالب محلی: تأیید حساب. ابتدا قالب را در پنل SMS.ir ایجاد و تأیید کنید؛ سپس شناسه و نام دقیق پارامتر کد را در تنظیمات اتصال وارد کنید. این افزونه قالب‌های پنل را ایجاد، ویرایش یا حذف نمی‌کند.',
    'Template ID': 'شناسه قالب',
    'OTP parameter': 'پارامتر کد یک‌بارمصرف',
    'Add [ani_sms_auth] to a WordPress page. HTTPS is required. Exclude that page and admin-ajax authentication responses from page/CDN caching. Browser tokens are fetched separately and never embedded in cached HTML.': 'برای نمایش فرم، [ani_sms_auth] را در یک برگه قرار دهید. HTTPS الزامی است. برگه ورود و پاسخ‌های احراز هویت admin-ajax را از کش سایت و CDN مستثنا کنید. توکن مرورگر جداگانه دریافت می‌شود و در HTML کش‌شده قرار نمی‌گیرد.',
    'Server setup: configure ANI_SMS_SECRET_KEY as base64 of 32 random bytes outside the database, then enable ANI_SMS_LIVE_ENABLED only after reviewing the API contract and test account. API keys may also be supplied through ANI_SMS_API_KEY. Never commit these values.': 'تنظیم سرور: ANI_SMS_SECRET_KEY را خارج از دیتابیس به‌صورت base64 از ۳۲ بایت تصادفی تعریف کنید. ANI_SMS_LIVE_ENABLED را فقط پس از بررسی قرارداد API و حساب آزمایشی فعال کنید. کلید API را می‌توان از ANI_SMS_API_KEY هم تأمین کرد. این مقادیر نباید وارد مخزن شوند.',
    'Logs are kept for 30 days without message bodies or OTP values. Expired challenges are cleaned after one hour and rate metadata after 24 hours beyond its window. Deactivation stops authentication and preserves permanent data. Configure a system cron at least every five minutes on low-traffic sites.': 'رخدادها بدون متن پیام و کد ورود تا ۳۰ روز نگهداری می‌شوند. داده کدهای منقضی و محدودسازی به‌صورت دوره‌ای پاک می‌شود. غیرفعال‌سازی، ورود پیامکی را متوقف می‌کند و داده دائمی را حفظ می‌کند. در سایت کم‌ترافیک، cron سیستم را طبق راهنما تنظیم کنید.',
    'On uninstall, permanently delete ANI-SMS settings, credentials, identity links and logs. WordPress users and orders will remain.': 'هنگام حذف افزونه، تنظیمات، کلید ذخیره‌شده، اتصال شماره‌ها و رخدادهای ANI-SMS برای همیشه پاک شوند. کاربران و سفارش‌های وردپرس حفظ می‌شوند.',
    'Save uninstall preference': 'ذخیره سیاست حذف افزونه',
    'Status': 'وضعیت',
    'All': 'همه',
    'Filter': 'اعمال فیلتر',
    'Time': 'زمان',
    'Operation': 'عملیات',
    'State': 'وضعیت',
    'Phone': 'شماره',
    'Error category': 'دسته خطا',
    'No events match this filter.': 'رخدادی با این فیلتر وجود ندارد.',
    'Previous': 'قبلی',
    'Next': 'بعدی',
    'Recent connection result is still current.': 'نتیجه بررسی اخیر هنوز معتبر است.',
    'Account connection succeeded. This does not verify the OTP template or message delivery.': 'اتصال حساب موفق بود. این نتیجه، درستی قالب ورود یا تحویل پیامک را تأیید نمی‌کند.',
    'Test message accepted by provider; delivery is not confirmed. This code cannot log in.': 'پیامک آزمایشی توسط سامانه پذیرفته شد؛ تحویل هنوز تأیید نشده است. این کد برای ورود معتبر نیست.',
    'Test not confirmed. Review logs; do not resend an unknown result blindly.': 'نتیجه ارسال آزمایشی تأیید نشد. رخدادها را بررسی کنید؛ ارسال نامشخص را بدون بررسی تکرار نکنید.',
    'The operation failed. Check the values and server configuration; secrets have not been displayed.': 'عملیات انجام نشد. مقادیر و تنظیمات سرور را بررسی کنید؛ اطلاعات محرمانه نمایش داده نشده است.',
    'You have unsaved changes.': 'تغییرات ذخیره‌نشده دارید.',
    'Working…': 'در حال انجام…',
    'Sending…': 'در حال ارسال…',
    'If this number is eligible, a code has been sent.': 'در صورت مجازبودن ورود با این شماره، کد برای شما ارسال شده است.',
    'Connection failed. Try again.': 'ارتباط برقرار نشد. دوباره تلاش کنید.',
    'Verified successfully.': 'تأیید با موفقیت انجام شد.',
    'The code has expired. Request a new code.': 'کد منقضی شده است. کد جدید درخواست کنید.',
    'Resend code': 'ارسال مجدد کد',
    'Wait': 'انتظار',
    'Authentication is unavailable. Use the standard login.': 'ورود پیامکی در دسترس نیست. از ورود استاندارد استفاده کنید.',
    'Verify and link your mobile': 'تأیید و اتصال شماره موبایل',
    'Login / Register': 'ورود / ثبت‌نام',
    'Mobile authentication is currently unavailable.': 'ورود پیامکی در حال حاضر در دسترس نیست.',
    'Standard WordPress login': 'ورود استاندارد وردپرس',
    'Your number is used to verify account ownership. Login does not subscribe you to marketing.': 'شماره شما برای تأیید مالکیت حساب استفاده می‌شود. ورود به معنی عضویت در پیامک تبلیغاتی نیست.',
    'Mobile number': 'شماره موبایل',
    'Current account password': 'رمز فعلی حساب',
    'Send verification code': 'دریافت کد تأیید',
    'Six-digit verification code': 'کد تأیید شش‌رقمی',
    'Verify': 'تأیید و ادامه',
    'Edit number': 'ویرایش شماره',
    'Enable JavaScript to use mobile authentication, or use the standard login.': 'برای ورود پیامکی جاوااسکریپت را فعال کنید یا از ورود استاندارد استفاده کنید.',
    'Privacy policy': 'حریم خصوصی',
    'Configure SMS.ir credentials and an approved OTP template.': 'کلید SMS.ir و شناسه قالب تأییدشده کد ورود را تنظیم کنید.',
    'SMS sending is disabled in server configuration.': 'ارسال پیامک در تنظیمات سرور غیرفعال است.',
    'Another authentication handler requires a reviewed compatibility adapter. Use the standard WordPress login.': 'سازگاری افزونه احراز هویت دیگری باید بررسی شود. فعلاً از ورود استاندارد وردپرس استفاده کنید.',
    'Configure the server secret key and sodium encryption.': 'کلید محرمانه سرور و رمزگذاری sodium را تنظیم کنید.',
    'Too many requests. Wait before trying again.': 'تعداد درخواست‌ها زیاد است. پیش از تلاش دوباره کمی صبر کنید.',
    'The request or code is invalid, expired, or unavailable for this account.': 'درخواست یا کد نامعتبر یا منقضی است، یا این روش برای حساب مجاز نیست.',
    'Authentication is unavailable. Use the standard login or try again later.': 'ورود پیامکی در دسترس نیست. از ورود استاندارد استفاده کنید یا بعداً تلاش کنید.',
    'Mobile authentication is disabled.': 'ورود پیامکی غیرفعال است.',
    'Member': 'عضو',
    'Verified mobile': 'موبایل تأییدشده',
    'Verified at (UTC)': 'زمان تأیید (UTC)',
    'ANI-SMS identity links and related challenges were removed. Masked aggregate message metadata expires under the retention policy.': 'اتصال شماره و داده کدهای مرتبط حذف شد. رخدادهای فاقد شماره کامل مطابق سیاست نگهداری پاک می‌شوند.',
    'ANI-SMS storage is unavailable. Verify MySQL InnoDB support and database privileges.': 'ذخیره‌سازی ANI-SMS در دسترس نیست. پشتیبانی InnoDB و مجوزهای دیتابیس را بررسی کنید.',
    'ANI-SMS requires InnoDB storage and database schema privileges.': 'ANI-SMS به ذخیره‌سازی InnoDB و مجوز ایجاد ساختار دیتابیس نیاز دارد.',
    'ANI-SMS requires WordPress 6.8 and PHP 8.3 or later.': 'ANI-SMS به وردپرس ۶٫۸ و PHP ۸٫۳ یا جدیدتر نیاز دارد.',
    'ANI-SMS multisite activation is not supported in this development build.': 'در این نسخه، فعال‌سازی ANI-SMS روی وردپرس شبکه پشتیبانی نمی‌شود.',
}
messages = set(translations)
pattern = re.compile(r"(?:__|_e|esc_html__|esc_html_e|translate)\(\s*'((?:\\.|[^'])*)'\s*,\s*'ani-sms'", re.S)
for file in root.rglob('*.php'):
    if 'languages' in file.parts:
        continue
    messages.update(m.replace("\\'", "'") for m in pattern.findall(file.read_text(encoding='utf-8')))
language_dir = root / 'languages'
language_dir.mkdir(exist_ok=True)
def quote(s):
    return json.dumps(s, ensure_ascii=False)
header = 'Project-Id-Version: ANI-SMS 1.0.0-rc2\nLanguage: fa_IR\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\nPlural-Forms: nplurals=2; plural=(n > 1);\n'
pot = 'msgid ""\nmsgstr ' + quote(header.replace('Language: fa_IR', 'Language: ')) + '\n\n'
po = 'msgid ""\nmsgstr ' + quote(header) + '\n\n'
for msg in sorted(messages):
    pot += f'msgid {quote(msg)}\nmsgstr ""\n\n'
    po += f'msgid {quote(msg)}\nmsgstr {quote(translations.get(msg, ""))}\n\n'
(language_dir / 'ani-sms.pot').write_text(pot, encoding='utf-8')
(language_dir / 'ani-sms-fa_IR.po').write_text(po, encoding='utf-8')
# PHP catalogs are supported natively by WordPress 6.5+, below our minimum 6.8.
def php(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"
entries = ',\n'.join('\t\t' + php(k) + ' => ' + php(v) for k, v in sorted(translations.items()))
catalog = "<?php\n/** Persian translations, generated from tools/translations.py. */\nreturn array(\n\t'project-id-version' => 'ANI-SMS 1.0.0-rc2',\n\t'language' => 'fa_IR',\n\t'plural-forms' => 'nplurals=2; plural=(n > 1);',\n\t'messages' => array(\n" + entries + "\n\t),\n);\n"
(language_dir / 'ani-sms-fa_IR.l10n.php').write_text(catalog, encoding='utf-8')
missing = messages - translations.keys()
print(f'Generated {len(translations)} Persian translations; {len(missing)} strings untranslated.')
for msg in missing:
    print(msg)
