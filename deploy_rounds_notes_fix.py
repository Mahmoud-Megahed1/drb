import ftplib
import os

# FTP Credentials
host = "145.223.86.39"
user = "u670530239"
passwd = "u?j?4F9wX/#r45R@"
remote_base_path = "domains/yellowgreen-quail-410393.hostingersite.com/public_html"

# Files for rounds stats + notes classification fixes
files_to_upload = [
    "admin/rounds_logs.php",
    "admin/rounds_scanner.php",
    "admin/notes_scanner.php",
    "admin/view_notes.php",
    "admin/qr_scanner.php",
    "admin/member_details.php",
    "admin/message_settings.php",
    "admin/save_whatsapp_messages.php",
    "admin/resend_approval.php",
    "admin/generate_acceptance.php",
    "admin/pending_messages.php",
    "admin/whatsapp_log.php",
    "profile.php",
    "member_profile.php",
    "dashboard.php",
    "approve_registration.php",
    "get_notes.php",
    "verify_round.php",
    "wasender.php",
    "include/WhatsAppLogger.php",
]


def ensure_remote_dir(ftp, remote_path):
    parts = remote_path.split("/")
    for i in range(len(parts)):
        subdir = "/".join(parts[: i + 1])
        if not subdir:
            continue
        try:
            ftp.mkd(subdir)
            print(f"Created remote directory: {subdir}")
        except ftplib.error_perm:
            pass


print("Deploying rounds and notes fixes...")
print(f"Connecting to FTP {host}...")

try:
    ftp = ftplib.FTP(host)
    ftp.login(user, passwd)
    print("Login successful.")

    ftp.cwd(remote_base_path)
    print(f"Changed directory to: {remote_base_path}")

    uploaded = 0

    for local_file in files_to_upload:
        if not os.path.exists(local_file):
            print(f"WARNING: Local file not found, skipping: {local_file}")
            continue

        remote_file = local_file.replace("\\", "/")
        remote_dir = os.path.dirname(remote_file)

        if remote_dir:
            ensure_remote_dir(ftp, remote_dir)

        print(f"Uploading {local_file} -> {remote_file}...")
        with open(local_file, "rb") as f:
            ftp.storbinary(f"STOR {remote_file}", f)
        uploaded += 1

    ftp.quit()
    print(f"\nSuccess! {uploaded}/{len(files_to_upload)} files uploaded.")

except Exception as e:
    print(f"\nFTP Error: {e}")
