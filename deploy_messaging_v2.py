import ftplib
import os

# FTP Credentials
host = '145.223.86.39'
user = 'u670530239'
passwd = 'u?j?4F9wX/#r45R@'
remote_base_path = 'domains/yellowgreen-quail-410393.hostingersite.com/public_html'

# All files modified in the messaging system redesign v2.0
files_to_upload = [
    "include/WhatsAppLogger.php",
    "wasender.php",
    "api/whatsapp_worker.php",
    "api/whatsapp_cron.php",
    "api/whatsapp_health.php",
    "api/whatsapp_monitor.php",
    "admin/pending_messages.php",
    "admin/whatsapp_log.php",
    "approve_registration.php",
    "migrate_messages.php",
]

def ensure_remote_dir(ftp, remote_path):
    parts = remote_path.split('/')
    for i in range(len(parts)):
        subdir = '/'.join(parts[:i+1])
        if not subdir: continue
        try:
            ftp.mkd(subdir)
            print(f"Created remote directory: {subdir}")
        except ftplib.error_perm:
            pass

print(f'🚀 Deploying Messaging System v2.0')
print(f'Connecting to FTP {host}...')
try:
    ftp = ftplib.FTP(host)
    ftp.login(user, passwd)
    print("✅ Login successful.")
    
    ftp.cwd(remote_base_path)
    print(f"📁 Changed directory to: {remote_base_path}")

    uploaded = 0
    for local_file in files_to_upload:
        if not os.path.exists(local_file):
            print(f"⚠️  WARNING: Local file not found, skipping: {local_file}")
            continue

        remote_file = local_file.replace('\\', '/')
        remote_dir = os.path.dirname(remote_file)
        
        if remote_dir:
            ensure_remote_dir(ftp, remote_dir)

        print(f'📤 Uploading {local_file} -> {remote_file}...')
        with open(local_file, 'rb') as f:
            ftp.storbinary(f'STOR {remote_file}', f)
        uploaded += 1

    ftp.quit()
    print(f"\n✅ Success! {uploaded}/{len(files_to_upload)} files uploaded.")
    print("\n⚠️  IMPORTANT: After deployment, run the migration:")
    print("   Visit: https://yellowgreen-quail-410393.hostingersite.com/migrate_messages.php")
    print("   (Must be logged in as admin)")
    print("\n📋 Then set up cron (Hostinger Panel → Cron Jobs):")
    print("   Every minute: wget -q -O /dev/null 'https://yellowgreen-quail-410393.hostingersite.com/api/whatsapp_cron.php?key=cron_secret_2026'")

except Exception as e:
    print(f"\n❌ FTP Error: {e}")
