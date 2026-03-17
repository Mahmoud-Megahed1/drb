import ftplib
import os

# FTP Credentials
host = '145.223.86.39'
user = 'u670530239'
passwd = 'u?j?4F9wX/#r45R@'
remote_base_path = 'domains/yellowgreen-quail-410393.hostingersite.com/public_html'

# List of modified files from recent commits
files_to_upload = [
    "include/helpers.php",
    "process.php",
    "services/MemberService.php",
    "admin/member_details.php",
    "admin/reset_championship.php",
    "dashboard.php",
    "add_note.php",
    "admin/generate_acceptance.php",
    "admin/view_notes.php",
    "admin/pending_messages.php",
    "database/schema.sql",
    "include/db.php",
    "index.php",
    "wasender.php",
    "super_clean.php",
    "approve_registration.php"
]

def ensure_remote_dir(ftp, remote_path):
    """Ensures that a remote directory exists by creating it recursively."""
    parts = remote_path.split('/')
    for i in range(len(parts)):
        subdir = '/'.join(parts[:i+1])
        if not subdir: continue
        try:
            ftp.mkd(subdir)
            print(f"Created remote directory: {subdir}")
        except ftplib.error_perm:
            # Directory already exists or permission denied
            pass

print(f'Connecting to FTP {host}...')
try:
    ftp = ftplib.FTP(host)
    ftp.login(user, passwd)
    print("Login successful.")
    
    # Switch to public_html
    ftp.cwd(remote_base_path)
    print(f"Changed directory to: {remote_base_path}")

    for local_file in files_to_upload:
        if not os.path.exists(local_file):
            print(f"WARNING: Local file not found, skipping: {local_file}")
            continue

        remote_file = local_file.replace('\\', '/')
        remote_dir = os.path.dirname(remote_file)
        
        if remote_dir:
            ensure_remote_dir(ftp, remote_dir)

        print(f'Uploading {local_file} -> {remote_file}...')
        with open(local_file, 'rb') as f:
            ftp.storbinary(f'STOR {remote_file}', f)

    ftp.quit()
    print("\nSuccess! All files have been uploaded.")

except Exception as e:
    print(f"\nFTP Error: {e}")
