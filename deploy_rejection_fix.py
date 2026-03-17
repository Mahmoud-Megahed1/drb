import ftplib
import os

# FTP Credentials
host = '145.223.86.39'
user = 'u670530239'
passwd = 'u?j?4F9wX/#r45R@'
remote_base_path = 'domains/yellowgreen-quail-410393.hostingersite.com/public_html'

# List of modified files for the rejection fix
files_to_upload = [
    "dashboard.php",
    "approve_registration.php"
]

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
        print(f'Uploading {local_file} -> {remote_file}...')
        with open(local_file, 'rb') as f:
            ftp.storbinary(f'STOR {remote_file}', f)

    ftp.quit()
    print("\nSuccess! Fixes for Rejection actions have been uploaded.")

except Exception as e:
    print(f"\nFTP Error: {e}")
